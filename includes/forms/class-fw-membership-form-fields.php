<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Two form-builder fields for sign-up forms, registered by Membership (Forms knows
 * nothing about them): Username and Password.
 *
 * The item classes extend Forms' shared simple-item base, so they are declared only
 * once Forms has loaded it (`fw_option_type_form_builder_init`). The password field is
 * a SECRET type (`fw_ext_forms_secret_field_types`): never stored in Entries, never
 * emailed, never given to form actions as a field; the Register member action reads
 * it from the raw submit payload. It is never echoed back into the form either.
 */
class FW_Membership_Form_Fields {

	public static function init() {
		add_action( 'fw_option_type_form_builder_init', array( __CLASS__, '_action_register' ) );
		add_filter( 'fw_ext_forms_secret_field_types', array( __CLASS__, '_filter_secret' ) );
		add_filter( 'fw_ext_forms_actions', array( __CLASS__, '_filter_actions' ) );
	}

	/** @internal The "Register a member" action, on forms that use Forms' action registry. */
	public static function _filter_actions( $actions ) {
		if ( class_exists( 'FW_Forms_Action' ) ) {
			require_once dirname( __FILE__ ) . '/class-fw-membership-forms-action-register.php';
			$actions[] = new FW_Membership_Forms_Action_Register();
		}
		return $actions;
	}

	/** @internal */
	public static function _filter_secret( $types ) {
		$types   = (array) $types;
		$types[] = 'member-password';
		return $types;
	}

	/** @internal */
	public static function _action_register() {
		if ( ! class_exists( 'FW_Option_Type_Form_Builder_Item_Simple' ) || class_exists( 'FW_Option_Type_Form_Builder_Item_Member_Password' ) ) {
			return;
		}
		require dirname( __FILE__ ) . '/items/class-fw-membership-form-items.php';
	}

	/**
	 * The value of the first field of a type in a submission, from the raw payload.
	 *
	 * @param array  $payload Submit or validation payload (shortcode_to_item, form_values).
	 * @param string $type
	 *
	 * @return string|null Null when the form has no such field.
	 */
	public static function value_of_type( array $payload, $type ) {
		foreach ( (array) ( $payload['shortcode_to_item'] ?? array() ) as $shortcode => $item ) {
			if ( ( $item['type'] ?? '' ) === $type ) {
				$v = $payload['form_values'][ $shortcode ] ?? '';
				return is_scalar( $v ) ? (string) $v : '';
			}
		}
		return null;
	}
}
