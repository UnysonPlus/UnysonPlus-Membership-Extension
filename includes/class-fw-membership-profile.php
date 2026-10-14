<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Profile fields: extra details you choose to collect from members (company, phone,
 * birthday…), each for every plan or only some, shown on the member's account page and
 * on their page in the Membership app.
 *
 * Definitions live in one option; values are user meta `fw_mbr_<key>`, so they belong
 * to the WordPress account (the CRM module, when it arrives, mirrors them to the
 * contact). A sign-up form fills them when one of its fields has the same label.
 */
class FW_Membership_Profile {

	const OPTION = 'fw_ext_membership_profile_fields';
	const PREFIX = 'fw_mbr_';
	const TYPES  = array( 'text', 'textarea', 'select', 'checkbox', 'date', 'number', 'tel', 'url' );

	/**
	 * @return array[] key => { key, label, type, choices string[], required, plans int[], member_edit }
	 */
	public static function fields() {
		$out = array();
		foreach ( (array) get_option( self::OPTION, array() ) as $f ) {
			if ( ! empty( $f['key'] ) ) {
				$out[ $f['key'] ] = self::shape( (array) $f );
			}
		}
		return $out;
	}

	/**
	 * @param array $rows Raw rows from the admin form.
	 *
	 * @return true|WP_Error
	 */
	public static function save_fields( array $rows ) {
		$out  = array();
		$seen = array();
		foreach ( $rows as $r ) {
			$label = sanitize_text_field( (string) ( $r['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$key = sanitize_key( (string) ( $r['key'] ?? '' ) );
			$key = '' !== $key ? $key : sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );
			$key = substr( $key, 0, 40 );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				return new WP_Error( 'fw_mbr_field_key', sprintf( __( 'Two fields would share the key "%s". Give one a different key.', 'fw' ), $key ) );
			}
			$seen[ $key ] = true;
			$out[]        = self::shape( array(
				'key'         => $key,
				'label'       => $label,
				'type'        => $r['type'] ?? 'text',
				'choices'     => is_array( $r['choices'] ?? null ) ? $r['choices'] : preg_split( '/\s*[\r\n,]+\s*/', (string) ( $r['choices'] ?? '' ) ),
				'required'    => ! empty( $r['required'] ),
				'plans'       => (array) ( $r['plans'] ?? array() ),
				'member_edit' => ! empty( $r['member_edit'] ),
				'directory'   => (string) ( $r['directory'] ?? 'hidden' ),
			) );
		}
		update_option( self::OPTION, $out, false );
		return true;
	}

	private static function shape( array $f ) {
		$type = in_array( $f['type'] ?? '', self::TYPES, true ) ? $f['type'] : 'text';
		return array(
			'key'         => sanitize_key( (string) $f['key'] ),
			'label'       => (string) ( $f['label'] ?? '' ),
			'type'        => $type,
			'choices'     => 'select' === $type ? array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $f['choices'] ?? array() ) ) ) ) ) : array(),
			'required'    => ! empty( $f['required'] ),
			'plans'       => array_values( array_filter( array_map( 'intval', (array) ( $f['plans'] ?? array() ) ) ) ),
			'member_edit' => ! array_key_exists( 'member_edit', $f ) || ! empty( $f['member_edit'] ),
			// Who sees it in the member directory (Directory module): hidden | members | public.
			'directory'   => in_array( $f['directory'] ?? '', array( 'members', 'public' ), true ) ? $f['directory'] : 'hidden',
		);
	}

	/**
	 * The fields that apply to a user: those for every plan, and those for a plan they hold.
	 *
	 * @param int $user_id
	 *
	 * @return array[]
	 */
	public static function fields_for_user( $user_id ) {
		$plans = FW_Membership_Service::user_plans( (int) $user_id );
		return array_filter( self::fields(), function ( $f ) use ( $plans ) {
			return ! $f['plans'] || array_intersect( $f['plans'], $plans );
		} );
	}

	/**
	 * @param int    $user_id
	 * @param string $key
	 *
	 * @return string
	 */
	public static function get( $user_id, $key ) {
		return (string) get_user_meta( (int) $user_id, self::PREFIX . $key, true );
	}

	/**
	 * Validate and store values. Only the given fields are touched.
	 *
	 * @param int     $user_id
	 * @param array[] $fields  The definitions allowed here.
	 * @param array   $values  key => raw value
	 * @param bool    $partial True: a missing required value is not an error (sign-up forms
	 *                         fill what they have).
	 *
	 * @return true|WP_Error
	 */
	public static function save_values( $user_id, array $fields, array $values, $partial = false ) {
		$clean = array();
		foreach ( $fields as $key => $f ) {
			if ( ! array_key_exists( $key, $values ) ) {
				if ( 'checkbox' === $f['type'] && ! $partial ) {
					$clean[ $key ] = '';
				}
				continue;
			}
			$v = self::clean( $f, $values[ $key ] );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
			if ( '' === $v && $f['required'] && ! $partial ) {
				return new WP_Error( 'fw_mbr_field_required', sprintf( __( '%s is required.', 'fw' ), $f['label'] ) );
			}
			$clean[ $key ] = $v;
		}
		foreach ( $clean as $key => $v ) {
			if ( '' === $v ) {
				delete_user_meta( (int) $user_id, self::PREFIX . $key );
			} else {
				update_user_meta( (int) $user_id, self::PREFIX . $key, $v );
			}
		}
		/** Fires after a member's profile fields were saved. */
		do_action( 'unysonplus_membership_profile_saved', (int) $user_id, $clean );
		return true;
	}

	/**
	 * @param array $f
	 * @param mixed $raw
	 *
	 * @return string|WP_Error
	 */
	private static function clean( array $f, $raw ) {
		$v = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' === $v ) {
			return '';
		}
		switch ( $f['type'] ) {
			case 'textarea':
				return sanitize_textarea_field( $v );
			case 'select':
				return in_array( $v, $f['choices'], true ) ? $v : new WP_Error( 'fw_mbr_field_choice', sprintf( __( 'Choose one of the options for %s.', 'fw' ), $f['label'] ) );
			case 'checkbox':
				return in_array( strtolower( $v ), array( '1', 'yes', 'on', 'true' ), true ) ? '1' : '';
			case 'date':
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) && strtotime( $v ) ? $v : new WP_Error( 'fw_mbr_field_date', sprintf( __( '%s is not a valid date.', 'fw' ), $f['label'] ) );
			case 'number':
				return is_numeric( $v ) ? (string) ( 0 + $v ) : new WP_Error( 'fw_mbr_field_number', sprintf( __( '%s must be a number.', 'fw' ), $f['label'] ) );
			case 'url':
				$u = esc_url_raw( $v );
				return '' !== $u ? $u : new WP_Error( 'fw_mbr_field_url', sprintf( __( '%s is not a valid address.', 'fw' ), $f['label'] ) );
			case 'tel':
				return preg_replace( '/[^0-9+()\-.\s]/', '', $v );
		}
		return sanitize_text_field( $v );
	}

	/**
	 * Fill profile fields from a sign-up form: a form field whose label matches a profile
	 * field's label (or key) fills it.
	 *
	 * @param int   $user_id
	 * @param array $form_fields Entry-style rows.
	 */
	public static function fill_from_form( $user_id, array $form_fields ) {
		$by_label = array();
		foreach ( self::fields() as $key => $f ) {
			$by_label[ strtolower( trim( $f['label'] ) ) ] = $key;
			$by_label[ $key ]                               = $key;
		}
		$values = array();
		foreach ( $form_fields as $row ) {
			$label = strtolower( trim( (string) ( $row['label'] ?? '' ) ) );
			if ( isset( $by_label[ $label ] ) && is_scalar( $row['value'] ?? null ) ) {
				$values[ $by_label[ $label ] ] = $row['value'];
			}
		}
		if ( $values ) {
			self::save_values( $user_id, self::fields(), $values, true );
		}
	}

	/**
	 * Form inputs for a set of fields, named fw_mbr_profile[key].
	 *
	 * @param int     $user_id
	 * @param array[] $fields
	 * @param string  $row_class
	 *
	 * @return string
	 */
	public static function inputs( $user_id, array $fields, $row_class = 'fw-mbr-form__row' ) {
		$out = '';
		foreach ( $fields as $key => $f ) {
			$id    = 'fw-mbr-pf-' . $key;
			$name  = 'fw_mbr_profile[' . $key . ']';
			$value = self::get( $user_id, $key );
			$req   = $f['required'] ? ' required' : '';
			$label = '<label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . ( $f['required'] ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>';
			switch ( $f['type'] ) {
				case 'textarea':
					$field = '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3"' . $req . '>' . esc_textarea( $value ) . '</textarea>';
					break;
				case 'select':
					$field = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req . '><option value="">' . esc_html__( '— Choose —', 'fw' ) . '</option>';
					foreach ( $f['choices'] as $c ) {
						$field .= '<option value="' . esc_attr( $c ) . '"' . selected( $value, $c, false ) . '>' . esc_html( $c ) . '</option>';
					}
					$field .= '</select>';
					break;
				case 'checkbox':
					$out .= '<p class="' . esc_attr( $row_class ) . ' fw-mbr-form__row--check"><input type="hidden" name="' . esc_attr( $name ) . '" value="" /><label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( $value, '1', false ) . ' /> ' . esc_html( $f['label'] ) . '</label></p>';
					continue 2;
				default:
					$type  = in_array( $f['type'], array( 'date', 'number', 'tel', 'url' ), true ) ? $f['type'] : 'text';
					$field = '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $req . ' />';
			}
			$out .= '<p class="' . esc_attr( $row_class ) . '">' . $label . $field . '</p>';
		}
		return $out;
	}

	/**
	 * The posted fw_mbr_profile[] values, unslashed.
	 *
	 * @return array
	 */
	public static function posted() {
		return isset( $_POST['fw_mbr_profile'] ) && is_array( $_POST['fw_mbr_profile'] ) ? (array) wp_unslash( $_POST['fw_mbr_profile'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification -- callers verify.
	}

	/**
	 * Remove every stored value of a user (privacy erase).
	 *
	 * @param int $user_id
	 *
	 * @return int How many were removed.
	 */
	public static function erase( $user_id ) {
		$n = 0;
		foreach ( array_keys( self::fields() ) as $key ) {
			if ( '' !== self::get( $user_id, $key ) ) {
				delete_user_meta( (int) $user_id, self::PREFIX . $key );
				$n++;
			}
		}
		return $n;
	}
}
