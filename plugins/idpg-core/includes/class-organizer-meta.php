<?php
/**
 * Мета-поля организатора на экране пользователя в админке.
 *
 * Carbon Fields-контейнер `user_meta` с реквизитами и верификацией.
 * Поле `verified` доступно только администраторам.
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
	 * Инициализация хуков.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'carbon_fields_register_fields', array( __CLASS__, 'register_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'protect_verification_status' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'protect_verification_status' ) );
	}

	/**
	 * Регистрация полей Carbon Fields на экране пользователя.
	 *
	 * @return void
	 */
	public static function register_fields() {
		$fields = array(
			\Carbon_Fields\Field::make( 'select', 'idpg_legal_type', __( 'Организационно-правовая форма', 'idpg-core' ) )
				->add_options(
					array(
						''    => __( '— Не выбрана —', 'idpg-core' ),
						'ip'  => __( 'Индивидуальный предприниматель', 'idpg-core' ),
						'ooo' => __( 'Общество с ограниченной ответственностью', 'idpg-core' ),
						'fiz' => __( 'Физическое лицо', 'idpg-core' ),
						'nko' => __( 'Некоммерческая организация', 'idpg-core' ),
					)
				),
			\Carbon_Fields\Field::make( 'text', 'idpg_inn', __( 'ИНН', 'idpg-core' ) )
				->set_attribute( 'placeholder', '10 или 12 цифр' )
				->set_help_text( __( 'Только цифры, без пробелов.', 'idpg-core' ) ),
			\Carbon_Fields\Field::make( 'media_gallery', 'idpg_documents', __( 'Документы', 'idpg-core' ) )
				->set_duplicates_allowed( false )
				->set_help_text( __( 'PDF, сканы, изображения.', 'idpg-core' ) ),
		);

		// Статус верификации — только для администраторов.
		if ( current_user_can( 'manage_options' ) ) {
			$fields[] = \Carbon_Fields\Field::make( 'checkbox', 'idpg_verified', __( 'Верифицирован', 'idpg-core' ) )
				->set_help_text( __( 'Только администратор может изменять этот статус.', 'idpg-core' ) );
		}

		\Carbon_Fields\Container::make( 'user_meta', __( 'Реквизиты организатора', 'idpg-core' ) )
			->add_fields( $fields );
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
