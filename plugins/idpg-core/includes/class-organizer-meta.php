<?php
/**
 * Мета-поля организатора на экране пользователя в админке.
 *
 * Заменяет Carbon Fields-контейнер `user_meta`: реквизиты (ОРФ, ИНН,
 * документы) и статус верификации выводятся нативными полями на
 * экране профиля пользователя. Поле `verified` доступно только
 * администраторам.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Organizer_Meta
 */
class IDPG_Organizer_Meta {

	/**
	 * Действие nonce.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'idpg_save_organizer_meta';

	/**
	 * Имя поля nonce.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'idpg_organizer_meta_nonce';

	/**
	 * Инициализация хуков.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'protect_verification_status' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'protect_verification_status' ) );
	}

	/**
	 * Определения полей.
	 *
	 * @return array
	 */
	public static function get_fields() {
		$fields = array(
			array(
				'id'      => 'idpg_legal_type',
				'type'    => 'select',
				'label'   => __( 'Организационно-правовая форма', 'idpg-core' ),
				'options' => array(
					''    => __( '— Не выбрана —', 'idpg-core' ),
					'ip'  => __( 'Индивидуальный предприниматель', 'idpg-core' ),
					'ooo' => __( 'Общество с ограниченной ответственностью', 'idpg-core' ),
					'fiz' => __( 'Физическое лицо', 'idpg-core' ),
					'nko' => __( 'Некоммерческая организация', 'idpg-core' ),
				),
			),
			array(
				'id'          => 'idpg_inn',
				'type'        => 'text',
				'label'       => __( 'ИНН', 'idpg-core' ),
				'placeholder' => __( '10 или 12 цифр', 'idpg-core' ),
				'help'        => __( 'Только цифры, без пробелов.', 'idpg-core' ),
			),
			array(
				'id'    => 'idpg_documents',
				'type'  => 'documents',
				'label' => __( 'Документы', 'idpg-core' ),
				'help'  => __( 'PDF, сканы, изображения.', 'idpg-core' ),
			),
		);

		// Статус верификации — только для администраторов.
		if ( current_user_can( 'manage_options' ) ) {
			$fields[] = array(
				'id'    => 'idpg_verified',
				'type'  => 'checkbox',
				'label' => __( 'Верифицирован', 'idpg-core' ),
				'help'  => __( 'Только администратор может изменять этот статус.', 'idpg-core' ),
			);
		}

		return $fields;
	}

	/**
	 * Вывод полей на экране профиля пользователя.
	 *
	 * @param WP_User $user Объект пользователя.
	 * @return void
	 */
	public static function render_fields( $user ) {
		if ( ! user_can( $user, 'edit_user', $user->ID ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<h2>' . esc_html__( 'Реквизиты организатора', 'idpg-core' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		foreach ( self::get_fields() as $field ) {
			$value = get_user_meta( $user->ID, $field['id'], true );

			echo '<tr>';
			echo '<th scope="row"><label for="' . esc_attr( $field['id'] ) . '">' . esc_html( $field['label'] ) . '</label></th>';
			echo '<td>';
			self::render_field_control( $field, $value );
			if ( ! empty( $field['help'] ) ) {
				echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</table>';
	}

	/**
	 * Вывод управляющего элемента поля.
	 *
	 * @param array $field Описание поля.
	 * @param mixed $value Текущее значение.
	 * @return void
	 */
	protected static function render_field_control( $field, $value ) {
		$type = $field['type'];
		$id   = $field['id'];

		switch ( $type ) {
			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
				foreach ( $field['options'] as $opt_value => $opt_label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $opt_value ),
						selected( (string) $value, (string) $opt_value, false ),
						esc_html( $opt_label )
					);
				}
				echo '</select>';
				break;

			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
					esc_attr( $id ),
					checked( '1' === (string) $value || 'yes' === (string) $value, true, false ),
					esc_html( $field['label'] )
				);
				break;

			case 'documents':
				self::render_documents_field( $value );
				break;

			default:
				$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
				printf(
					'<input type="text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value ),
					esc_attr( $placeholder )
				);
				break;
		}
	}

	/**
	 * Вывод поля «Документы» (список ID вложений).
	 *
	 * @param mixed $value Сырое значение.
	 * @return void
	 */
	protected static function render_documents_field( $value ) {
		$ids = self::normalize_documents_value( $value );
		?>
		<div class="idpg-documents" id="idpg-documents-field">
			<input type="hidden" name="idpg_documents_ids" id="idpg_documents_ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" />
			<div class="idpg-documents__list">
				<?php foreach ( $ids as $att_id ) : ?>
					<?php $url = wp_get_attachment_url( $att_id ); ?>
					<?php if ( $url ) : ?>
						<span class="idpg-documents__item" data-id="<?php echo esc_attr( $att_id ); ?>">
							<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
								<?php $doc_title = get_the_title( $att_id ); ?>
								<?php echo esc_html( $doc_title ? $doc_title : basename( $url ) ); ?>
							</a>
							<button type="button" class="idpg-documents__remove" title="<?php esc_attr_e( 'Удалить', 'idpg-core' ); ?>">&times;</button>
						</span>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button idpg-documents__add"><?php esc_html_e( '+ Добавить документы', 'idpg-core' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Нормализация значения списка документов.
	 *
	 * @param mixed $value Сырое значение.
	 * @return int[]
	 */
	public static function normalize_documents_value( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'absint', $value );
		}

		if ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				return array_map( 'absint', $decoded );
			}
		}

		return array();
	}

	/**
	 * Сохранение полей реквизитов.
	 *
	 * @param int $user_id ID редактируемого пользователя.
	 * @return void
	 */
	public static function save_fields( $user_id ) {
		if ( empty( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		foreach ( self::get_fields() as $field ) {
			if ( 'documents' === $field['type'] ) {
				$raw = isset( $_POST['idpg_documents_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['idpg_documents_ids'] ) ) : '';
				$ids = array();

				if ( '' !== $raw ) {
					foreach ( explode( ',', $raw ) as $id ) {
						$id = absint( trim( $id ) );
						if ( $id ) {
							$ids[] = $id;
						}
					}
				}

				if ( empty( $ids ) ) {
					delete_user_meta( $user_id, $field['id'] );
				} else {
					update_user_meta( $user_id, $field['id'], $ids );
				}
				continue;
			}

			if ( 'checkbox' === $field['type'] ) {
				if ( isset( $_POST[ $field['id'] ] ) ) {
					update_user_meta( $user_id, $field['id'], '1' );
				} else {
					delete_user_meta( $user_id, $field['id'] );
				}
				continue;
			}

			$raw   = isset( $_POST[ $field['id'] ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field['id'] ] ) ) : '';
			$value = trim( $raw );

			if ( '' === $value ) {
				delete_user_meta( $user_id, $field['id'] );
			} else {
				update_user_meta( $user_id, $field['id'], $value );
			}
		}
	}

	/**
	 * Защита статуса верификации от изменения не-администраторами.
	 *
	 * Если текущий пользователь не имеет `manage_options`, значение
	 * `idpg_verified` сбрасывается к предыдущему.
	 *
	 * @param int $user_id ID редактируемого пользователя.
	 * @return void
	 */
	public static function protect_verification_status( $user_id ) {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		$previous = (bool) get_user_meta( (int) $user_id, 'idpg_verified', true );

		if ( $previous ) {
			update_user_meta( (int) $user_id, 'idpg_verified', '1' );
		} else {
			delete_user_meta( (int) $user_id, 'idpg_verified' );
		}
	}
}

IDPG_Organizer_Meta::init();
