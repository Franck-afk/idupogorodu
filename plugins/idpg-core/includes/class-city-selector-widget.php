<?php
/**
 * Виджет «Выбор города» для мультигородского портала.
 *
 * Отображает список городов из таксономии 'city' (иерархическая:
 * область → город → район), запоминает выбор в cookie на 30 дней
 * (через существующий AJAX IDPG_City_Filter::ajax_set_city),
 * умеет автоопределять город по IP и кэширует список в Transients.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_City_Selector_Widget
 */
class IDPG_City_Selector_Widget extends WP_Widget {

	/**
	 * Служебный id_base виджета.
	 *
	 * @var string
	 */
	const ID_BASE = 'idpg_city_selector';

	/**
	 * Таксономия городов.
	 *
	 * @var string
	 */
	const TAXONOMY = 'city';

	/**
	 * Время жизни кэша списка городов (сек).
	 *
	 * @var int
	 */
	const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Время жизни кэша автоопределения по IP (сек).
	 *
	 * @var int
	 */
	const IP_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Список доступных сервисов автоопределения.
	 *
	 * @return array
	 */
	public static function ip_services() {
		return array(
			'none'     => __( 'Не определять', 'idpg-core' ),
			'ip_api'   => __( 'ip-api.com', 'idpg-core' ),
			'ipapi_co' => __( 'ipapi.co', 'idpg-core' ),
		);
	}

	/**
	 * Конструктор.
	 */
	public function __construct() {
		parent::__construct(
			self::ID_BASE,
			__( 'Выбор города (IDPG)', 'idpg-core' ),
			array(
				'description' => __( 'Выпадающий список городов для мультигородского портала.', 'idpg-core' ),
			)
		);

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Регистрирует CSS/JS виджета (всегда) и подключает их, если виджет активен.
	 */
	public function register_assets() {
		// Tom Select (локально, без CDN).
		wp_register_style(
			'idpg-city-selector-tom-select',
			IDPG_CORE_URL . 'assets/vendor/tom-select/tom-select.min.css',
			array(),
			IDPG_CORE_VERSION
		);

		wp_register_style(
			'idpg-city-selector',
			IDPG_CORE_URL . 'assets/css/city-selector.css',
			array( 'idpg-city-selector-tom-select' ),
			IDPG_CORE_VERSION
		);

		wp_register_script(
			'idpg-city-selector-tom-select',
			IDPG_CORE_URL . 'assets/vendor/tom-select/tom-select.complete.min.js',
			array(),
			IDPG_CORE_VERSION,
			true
		);

		wp_register_script(
			'idpg-city-selector',
			IDPG_CORE_URL . 'assets/js/city-selector.js',
			array( 'idpg-city-selector-tom-select' ),
			IDPG_CORE_VERSION,
			true
		);

		wp_localize_script(
			'idpg-city-selector',
			'idpgCitySelector',
			array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'idpg_set_city' ),
				'searchPlaceholder' => __( 'Поиск города…', 'idpg-core' ),
			)
		);

		if ( ! is_active_widget( false, false, self::ID_BASE ) ) {
			return;
		}

		wp_enqueue_style( 'idpg-city-selector-tom-select' );
		wp_enqueue_style( 'idpg-city-selector' );
		wp_enqueue_script( 'idpg-city-selector-tom-select' );
		wp_enqueue_script( 'idpg-city-selector' );
	}

	/**
	 * Форма в админке.
	 *
	 * @param array $instance Текущие настройки.
	 */
	public function form( $instance ) {
		$title            = isset( $instance['title'] ) ? $instance['title'] : '';
		$only_with_events = ! empty( $instance['only_with_events'] );
		$sort             = isset( $instance['sort'] ) ? $instance['sort'] : 'alpha';
		$display          = isset( $instance['display'] ) ? $instance['display'] : 'dropdown';
		$ip_service       = isset( $instance['ip_service'] ) ? $instance['ip_service'] : 'none';

		$sorts = array(
			'alpha'   => __( 'Алфавит', 'idpg-core' ),
			'popular' => __( 'По популярности (кол-во событий)', 'idpg-core' ),
			'manual'  => __( 'Вручную (порядок в админке)', 'idpg-core' ),
		);
		$displays = array(
			'dropdown' => __( 'Выпадающий список', 'idpg-core' ),
			'buttons'  => __( 'Кнопки', 'idpg-core' ),
		);
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Заголовок:', 'idpg-core' ); ?>
			</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text"
				value="<?php echo esc_attr( $title ); ?>">
		</p>

		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'only_with_events' ) ); ?>" value="1"
					<?php checked( $only_with_events ); ?>>
				<?php esc_html_e( 'Показывать только города с событиями', 'idpg-core' ); ?>
			</label>
		</p>

		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'sort' ) ); ?>">
				<?php esc_html_e( 'Сортировка:', 'idpg-core' ); ?>
			</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'sort' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'sort' ) ); ?>">
				<?php foreach ( $sorts as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $sort, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'display' ) ); ?>">
				<?php esc_html_e( 'Тип отображения:', 'idpg-core' ); ?>
			</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'display' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'display' ) ); ?>">
				<?php foreach ( $displays as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $display, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'ip_service' ) ); ?>">
				<?php esc_html_e( 'Автоопределение города по IP:', 'idpg-core' ); ?>
			</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'ip_service' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'ip_service' ) ); ?>">
				<?php foreach ( self::ip_services() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $ip_service, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Сохранение настроек.
	 *
	 * @param array $new Новые данные.
	 * @param array $old Старые данные.
	 * @return array
	 */
	public function update( $new, $old ) {
		$instance = array();

		$instance['title']            = isset( $new['title'] ) ? sanitize_text_field( $new['title'] ) : '';
		$instance['only_with_events'] = ! empty( $new['only_with_events'] ) ? 1 : 0;

		$sort = isset( $new['sort'] ) ? sanitize_key( $new['sort'] ) : 'alpha';
		$instance['sort'] = in_array( $sort, array( 'alpha', 'popular', 'manual' ), true ) ? $sort : 'alpha';

		$display = isset( $new['display'] ) ? sanitize_key( $new['display'] ) : 'dropdown';
		$instance['display'] = in_array( $display, array( 'dropdown', 'buttons' ), true ) ? $display : 'dropdown';

		$ip_service = isset( $new['ip_service'] ) ? sanitize_key( $new['ip_service'] ) : 'none';
		$instance['ip_service'] = array_key_exists( $ip_service, self::ip_services() ) ? $ip_service : 'none';

		// Сброс кэша дерева городов при изменении настроек.
		$this->purge_tree_cache();

		return $instance;
	}

	/**
	 * Вывод виджета на фронте.
	 *
	 * @param array $args     Параметры области виджетов.
	 * @param array $instance Настройки.
	 */
	public function widget( $args, $instance ) {
		$title            = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Город', 'idpg-core' );
		$only_with_events = ! empty( $instance['only_with_events'] );
		$sort             = isset( $instance['sort'] ) ? $instance['sort'] : 'alpha';
		$display          = isset( $instance['display'] ) ? $instance['display'] : 'dropdown';
		$ip_service       = isset( $instance['ip_service'] ) ? $instance['ip_service'] : 'none';

		$tree = $this->get_city_tree( $sort );

		if ( empty( $tree ) ) {
			return;
		}

		// Текущий город: cookie → автоопределение по IP → первый город.
		$current = $this->resolve_current_city( $tree, $ip_service );

		// Фильтр «только с событиями».
		if ( $only_with_events ) {
			$tree = $this->filter_tree_with_events( $tree );
		}

		if ( empty( $tree ) ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( ! empty( $current['from_ip'] ) || IDPG_City_Filter::was_detected_from_ip() ) {
			echo '<p class="idpg-city-selector__ipnote">'
				. esc_html__( 'Мы определили ваш город автоматически. При необходимости выберите другой:', 'idpg-core' )
				. '</p>';
		}

		$submit_url = isset( $_SERVER['REQUEST_URI'] )
			? home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: home_url( '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( 'buttons' === $display ) {
			echo $this->render_buttons( $tree, (int) $current['id'], $submit_url ); // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo $this->render_dropdown( $tree, (int) $current['id'], $submit_url ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Получение дерева городов (область → город → район) с кэшем 24ч.
	 *
	 * @param string $sort Ключ сортировки.
	 * @return array
	 */
	private function get_city_tree( $sort ) {
		static $cache = array();

		if ( isset( $cache[ $sort ] ) ) {
			return $cache[ $sort ];
		}

		$transient_key = 'idpg_city_tree_' . md5( $sort );
		$tree          = get_transient( $transient_key );

		if ( false === $tree ) {
			$tree = $this->build_city_tree( $sort );
			set_transient( $transient_key, $tree, self::CACHE_TTL );
		}

		$cache[ $sort ] = $tree;

		return $tree;
	}

	/**
	 * Построение дерева городов одним запросом к БД (get_terms).
	 *
	 * @param string $sort Ключ сортировки.
	 * @return array
	 */
	private function build_city_tree( $sort ) {
		$orderby = 'name';
		$order   = 'ASC';

		if ( 'popular' === $sort ) {
			$orderby = 'count';
			$order   = 'DESC';
		} elseif ( 'manual' === $sort ) {
			$orderby = 'term_order';
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => $orderby,
				'order'      => $order,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$flatten = array();
		foreach ( $terms as $term ) {
			$flatten[ (int) $term->term_id ] = array(
				'id'     => (int) $term->term_id,
				'parent' => (int) $term->parent,
				'name'   => $term->name,
				'slug'   => $term->slug,
				'count'  => (int) $term->count,
			);
		}

		$tree = array();
		foreach ( $flatten as $id => $node ) {
			if ( 0 === $node['parent'] ) {
				$tree[ $id ] = $node;
			}
		}

		// Второй проход: прикрепляем города (дети областей) и районы (дети городов).
		foreach ( $flatten as $id => $node ) {
			if ( 0 === $node['parent'] ) {
				continue;
			}
			$parent = $node['parent'];
			if ( isset( $tree[ $parent ] ) ) {
				$tree[ $parent ]['children'][ $id ] = $node;
				continue;
			}
			// Район (уровень 3): ищем его город-родителя внутри области и записываем по ссылке.
			foreach ( $tree as &$region ) {
				if ( isset( $region['children'][ $parent ] ) ) {
					$region['children'][ $parent ]['children'][ $id ] = $node;
					break;
				}
			}
			unset( $region );
		}

		return array_values( $tree );
	}

	/**
	 * Фильтр дерева «только города с событиями».
	 *
	 * @param array $tree Дерево.
	 * @return array
	 */
	private function filter_tree_with_events( $tree ) {
		return array_values( array_filter( $tree, array( $this, 'keep_if_has_events' ) ) );
	}

	/**
	 * Рекурсивно: у узла есть события либо среди детей.
	 *
	 * @param array $node Узел.
	 * @return bool
	 */
	private function keep_if_has_events( $node ) {
		$has_children = ! empty( $node['children'] );

		if ( $has_children ) {
			$node['children'] = array_values( array_filter( $node['children'], array( $this, 'keep_if_has_events' ) ) );
		}

		return ( 0 < (int) $node['count'] ) || ( $has_children && ! empty( $node['children'] ) );
	}

	/**
	 * Определение текущего города.
	 *
	 * Порядок: cookie → автоопределение по IP → первый город дерева.
	 *
	 * @param array  $tree       Дерево городов.
	 * @param string $ip_service Настройка сервиса IP.
	 * @return array Узел ['id','name','slug','from_ip'].
	 */
	private function resolve_current_city( $tree, $ip_service ) {
		// Город считается выбранным только если cookie реально установлен
		// (get_current_city_id() без cookie возвращает дефолтный город).
		$cookie_id = isset( $_COOKIE[ IDPG_City_Filter::COOKIE_NAME ] )
			? (int) IDPG_City_Filter::get_current_city_id()
			: 0;

		if ( $cookie_id && ( $node = $this->find_node_by_id( $tree, $cookie_id ) ) ) {
			return array(
				'id'      => (int) $node['id'],
				'name'    => $node['name'],
				'slug'    => $node['slug'],
				'from_ip' => false,
			);
		}

		// Автоопределение по IP (только если cookie ещё не установлен).
		if ( 'none' !== $ip_service && ! isset( $_COOKIE[ IDPG_City_Filter::COOKIE_NAME ] ) ) {
			$detected = $this->detect_city_by_ip( $ip_service, $tree );
			if ( $detected ) {
				return $detected;
			}
		}

		// Fallback: первый город дерева.
		$first = $this->first_city( $tree );
		if ( $first ) {
			return $first;
		}

		return array( 'id' => 0, 'name' => '', 'slug' => '', 'from_ip' => false );
	}

	/**
	 * Поиск узла по id во всём дереве (область→город→район).
	 *
	 * @param array $tree Дерево.
	 * @param int   $id   ID.
	 * @return array|null
	 */
	private function find_node_by_id( $tree, $id ) {
		foreach ( $tree as $node ) {
			if ( (int) $node['id'] === (int) $id ) {
				return $node;
			}
			if ( ! empty( $node['children'] ) ) {
				foreach ( $node['children'] as $child ) {
					if ( (int) $child['id'] === (int) $id ) {
						return $child;
					}
					if ( ! empty( $child['children'] ) ) {
						foreach ( $child['children'] as $grand ) {
							if ( (int) $grand['id'] === (int) $id ) {
								return $grand;
							}
						}
					}
				}
			}
		}
		return null;
	}

	/**
	 * Первый город в дереве.
	 *
	 * @param array $tree Дерево.
	 * @return array|null
	 */
	private function first_city( $tree ) {
		foreach ( $tree as $node ) {
			if ( ! empty( $node['children'] ) ) {
				foreach ( $node['children'] as $child ) {
					return array(
						'id'      => (int) $child['id'],
						'name'    => $child['name'],
						'slug'    => $child['slug'],
						'from_ip' => false,
					);
				}
			}
			// Плоская структура: термин без детей — сам город.
			return array(
				'id'      => (int) $node['id'],
				'name'    => $node['name'],
				'slug'    => $node['slug'],
				'from_ip' => false,
			);
		}
		return null;
	}

	/**
	 * Автоопределение города по IP.
	 *
	 * Использует общий механизм IDPG_City_Filter::lookup_city_id_by_ip()
	 * (единый transient-кэш, без дублирующих HTTP-запросов).
	 *
	 * @param string $service Ключ сервиса (совместимость сигнатуры; поиск идёт по всем сервисам).
	 * @param array  $tree    Дерево городов.
	 * @return array|null
	 */
	private function detect_city_by_ip( $service, $tree ) {
		unset( $service );

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! $ip || $this->is_local_ip( $ip ) ) {
			return null;
		}

		$cached = IDPG_City_Filter::get_ip_detection( $ip );
		$term_id = is_array( $cached )
			? ( 'ok' === $cached['status'] ? (int) $cached['term_id'] : 0 )
			: IDPG_City_Filter::lookup_city_id_by_ip( $ip );

		if ( ! $term_id ) {
			return null;
		}

		$node = $this->find_node_by_id( $tree, $term_id );

		if ( ! $node ) {
			return null;
		}

		return array(
			'id'      => (int) $node['id'],
			'name'    => $node['name'],
			'slug'    => $node['slug'],
			'from_ip' => true,
		);
	}

	/**
	 * Проверка, что IP локальный/приватный.
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	private function is_local_ip( $ip ) {
		return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * HTML выпадающего списка.
	 *
	 * @param array  $tree       Дерево.
	 * @param int    $current_id Текущий город.
	 * @param string $submit_url URL для no-JS формы.
	 * @return string
	 */
	private function render_dropdown( $tree, $current_id, $submit_url ) {
		$html  = '<form class="idpg-city-selector idpg-city-selector--dropdown" data-idpg-city-form action="'
			. esc_url( $submit_url ) . '" method="get">';
		$html .= '<label class="idpg-city-selector__select-label" for="' . esc_attr( $this->get_field_id( 'city' ) ) . '">'
			. esc_html__( 'Выберите город', 'idpg-core' ) . '</label>';
		$html .= '<select class="idpg-city-selector__select" id="' . esc_attr( $this->get_field_id( 'city' ) ) . '" name="city" data-idpg-city>';

		foreach ( $tree as $region ) {
			if ( empty( $region['children'] ) ) {
				// Плоская структура: термин верхнего уровня без детей — это сам город.
				$html .= '<option value="' . esc_attr( $region['slug'] ) . '" data-id="' . esc_attr( $region['id'] ) . '"'
					. selected( ( (int) $region['id'] === (int) $current_id ), true, false ) . '>'
					. esc_html( $region['name'] ) . '</option>';
				continue;
			}
			$html .= '<optgroup label="' . esc_attr( $region['name'] ) . '">';
			foreach ( $region['children'] as $city ) {
				$html .= '<option value="' . esc_attr( $city['slug'] ) . '" data-id="' . esc_attr( $city['id'] ) . '"'
					. selected( ( (int) $city['id'] === (int) $current_id ), true, false ) . '>'
					. esc_html( $city['name'] ) . '</option>';

				foreach ( $this->children_of( $city ) as $district ) {
					$html .= '<option value="' . esc_attr( $district['slug'] ) . '" data-id="' . esc_attr( $district['id'] ) . '"'
						. selected( ( (int) $district['id'] === (int) $current_id ), true, false ) . '>';
					$html .= "\u{00A0}\u{00A0}\u{2014} " . esc_html( $district['name'] ) . '</option>';
				}
			}
			$html .= '</optgroup>';
		}
		$html .= '</select>';
		$html .= '<noscript><button type="submit" class="idpg-city-selector__submit">'
			. esc_html__( 'Применить', 'idpg-core' ) . '</button></noscript>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * HTML кнопок.
	 *
	 * @param array  $tree       Дерево.
	 * @param int    $current_id Текущий город.
	 * @param string $submit_url URL (для кнопок не используется).
	 * @return string
	 */
	private function render_buttons( $tree, $current_id, $submit_url ) {
		$html = '<div class="idpg-city-selector idpg-city-selector--buttons" data-idpg-city>';

		foreach ( $tree as $region ) {
			if ( empty( $region['children'] ) ) {
				// Плоская структура: сам регион — город.
				$active = ( (int) $region['id'] === (int) $current_id );
				$html .= '<button type="button" class="idpg-city-selector__city'
					. ( $active ? ' is-active' : '' ) . '" data-id="' . esc_attr( $region['id'] ) . '" data-slug="'
					. esc_attr( $region['slug'] ) . '">' . esc_html( $region['name'] ) . '</button>';
				continue;
			}
			$html .= '<span class="idpg-city-selector__region">' . esc_html( $region['name'] ) . '</span>';
			foreach ( $region['children'] as $city ) {
				$active = ( (int) $city['id'] === (int) $current_id );
				$html .= '<button type="button" class="idpg-city-selector__city'
					. ( $active ? ' is-active' : '' ) . '" data-id="' . esc_attr( $city['id'] ) . '" data-slug="'
					. esc_attr( $city['slug'] ) . '">' . esc_html( $city['name'] ) . '</button>';
			}
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Районы внутри города.
	 *
	 * @param array $node Узел города.
	 * @return array
	 */
	private function children_of( $node ) {
		return isset( $node['children'] ) ? $node['children'] : array();
	}

	/**
	 * Очистка кэшей дерева городов.
	 */
	private function purge_tree_cache() {
		foreach ( array( 'alpha', 'popular', 'manual' ) as $sort ) {
			delete_transient( 'idpg_city_tree_' . md5( $sort ) );
		}
	}
}
