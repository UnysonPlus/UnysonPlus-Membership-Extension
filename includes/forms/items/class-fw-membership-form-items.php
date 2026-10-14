<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Username and Password fields. Loaded by FW_Membership_Form_Fields once Forms has
 * declared its simple-item base.
 */

/**
 * The parts both fields share: assets and views live in this extension, not in Forms.
 */
abstract class FW_Membership_Form_Item extends FW_Option_Type_Form_Builder_Item_Simple {

	protected function get_uri( $append = '' ) {
		return fw_ext( 'membership' )->get_uri() . '/includes/forms/items/' . $this->slug() . $append;
	}

	public function enqueue_static() {
		$ver = fw_ext( 'membership' )->manifest->get_version();
		wp_enqueue_script( 'fw-builder-form-builder-simple-item', fw_min_uri( $this->shared_uri( '/js/simple-item.js' ) ), array( 'fw-events' ), fw_ext( 'forms' )->manifest->get_version(), true );
		wp_enqueue_script( 'fw-builder-form-builder-item-' . $this->get_type(), $this->get_uri( '/scripts.js' ), array( 'fw-events', 'fw-builder-form-builder-simple-item' ), $ver, true );
		fw()->backend->enqueue_options_static( $this->get_options() );
	}

	protected function view( array $item, array $attr, array $extra = array() ) {
		return fw_render_view(
			dirname( __FILE__ ) . '/view.php',
			array_merge( array( 'item' => $item, 'attr' => $attr, 'options' => $item['options'] ), $extra )
		);
	}

	public function get_thumbnails() {
		return array(
			array(
				'html' =>
					'<div class="item-type-icon-title" data-hover-tip="' . esc_attr( $this->tip() ) . '">' .
					'<div class="item-type-icon"><img src="' . esc_attr( $this->get_uri( '/icon.svg' ) ) . '" alt="" /></div>' .
					'<div class="item-type-title">' . esc_html( $this->title() ) . '</div>' .
					'</div>',
			),
		);
	}

	/** @return string Folder under includes/forms/items/. */
	abstract protected function slug();
}

/**
 * Username — optional on a sign-up form; without it the username is made from the
 * email address.
 */
class FW_Option_Type_Form_Builder_Item_Member_Username extends FW_Membership_Form_Item {

	public function get_type() {
		return 'member-username';
	}

	protected function slug() {
		return 'username';
	}

	protected function title() {
		return __( 'Username', 'fw' );
	}

	protected function tip() {
		return __( 'Add a Username field for a sign-up form (Membership). Without it, the username is made from the email address.', 'fw' );
	}

	public function get_options() {
		return array(
			$this->label_group( __( 'Username', 'fw' ), true ),
			$this->info_group(),
			$this->get_extra_options(),
		);
	}

	public function frontend_render( array $item, $input_value ) {
		$attr = array(
			'type'           => 'text',
			'name'           => $item['shortcode'] ?? '',
			'value'          => is_scalar( $input_value ) ? (string) $input_value : '',
			'id'             => 'id-' . fw_unique_increment(),
			'autocomplete'   => 'username',
			'autocapitalize' => 'none',
			'spellcheck'     => 'false',
			'maxlength'      => '60',
		);
		if ( ! empty( $item['options']['required'] ) ) {
			$attr['required'] = 'required';
		}
		return $this->view( $item, $attr );
	}

	public function frontend_validate( array $item, $input_value ) {
		$value = is_scalar( $input_value ) ? trim( (string) $input_value ) : '';
		if ( '' === $value ) {
			return ! empty( $item['options']['required'] ) ? $this->msg( $item, __( 'The {label} field is required', 'fw' ) ) : null;
		}
		if ( sanitize_user( $value, true ) !== $value || ! validate_username( $value ) ) {
			return $this->msg( $item, __( 'The {label} can only use letters, numbers, spaces and . - _ @', 'fw' ) );
		}
		if ( strlen( $value ) < 3 ) {
			return $this->msg( $item, __( 'The {label} must be at least 3 characters', 'fw' ) );
		}
		if ( username_exists( $value ) ) {
			return $this->msg( $item, __( 'That {label} is taken. Please choose another.', 'fw' ) );
		}
		return null;
	}

	public function get_value_from_item( $value ) {
		return is_scalar( $value ) ? sanitize_user( trim( (string) $value ), true ) : '';
	}
}

/**
 * Password — with an optional "repeat password" box and a minimum length. A SECRET
 * field: see FW_Membership_Form_Fields.
 */
class FW_Option_Type_Form_Builder_Item_Member_Password extends FW_Membership_Form_Item {

	public function get_type() {
		return 'member-password';
	}

	protected function slug() {
		return 'password';
	}

	protected function title() {
		return __( 'Password', 'fw' );
	}

	protected function tip() {
		return __( 'Add a Password field for a sign-up form (Membership). It is never stored with the entry or emailed. Without it, new members get an email to set their password.', 'fw' );
	}

	public function get_options() {
		return array(
			$this->label_group( __( 'Password', 'fw' ), true ),
			array(
				'g_password' => array(
					'type'    => 'group',
					'options' => array(
						array(
							'confirm' => array(
								'type'  => 'switch',
								'label' => __( 'Ask twice', 'fw' ),
								'desc'  => __( 'Show a second box to repeat the password.', 'fw' ),
								'value' => true,
							),
						),
						array(
							'min_length' => array(
								'type'  => 'short-text',
								'label' => __( 'Minimum length', 'fw' ),
								'value' => '8',
							),
						),
					),
				),
			),
			$this->info_group(),
			$this->get_extra_options(),
		);
	}

	private function min_length( array $o ) {
		return max( 6, min( 64, (int) ( $o['min_length'] ?? 8 ) ) );
	}

	public function frontend_render( array $item, $input_value ) {
		$o    = $item['options'];
		$attr = array(
			'type'         => 'password',
			'name'         => $item['shortcode'] ?? '',
			'value'        => '', // never echo a password back
			'id'           => 'id-' . fw_unique_increment(),
			'autocomplete' => 'new-password',
			'minlength'    => (string) $this->min_length( $o ),
		);
		if ( ! empty( $o['required'] ) ) {
			$attr['required'] = 'required';
		}
		$confirm = null;
		if ( ! empty( $o['confirm'] ) ) {
			$confirm = array(
				'type'         => 'password',
				'name'         => ( $item['shortcode'] ?? '' ) . '_confirm',
				'value'        => '',
				'id'           => $attr['id'] . '-confirm',
				'autocomplete' => 'new-password',
			);
			if ( ! empty( $o['required'] ) ) {
				$confirm['required'] = 'required';
			}
		}
		return $this->view( $item, $attr, array( 'confirm' => $confirm ) );
	}

	public function frontend_validate( array $item, $input_value ) {
		$o     = $item['options'];
		$value = is_scalar( $input_value ) ? (string) $input_value : '';
		if ( '' === $value ) {
			return ! empty( $o['required'] ) ? $this->msg( $item, __( 'The {label} field is required', 'fw' ) ) : null;
		}
		if ( strlen( $value ) < $this->min_length( $o ) ) {
			return $this->msg( $item, sprintf( __( 'The {label} must be at least %d characters', 'fw' ), $this->min_length( $o ) ) );
		}
		if ( strlen( $value ) > 200 ) {
			return $this->msg( $item, __( 'The {label} is too long', 'fw' ) );
		}
		if ( ! empty( $o['confirm'] ) ) {
			$repeat = FW_Request::POST( ( $item['shortcode'] ?? '' ) . '_confirm' );
			if ( ! is_string( $repeat ) || ! hash_equals( $value, $repeat ) ) {
				return $this->msg( $item, __( 'The two {label} boxes do not match', 'fw' ) );
			}
		}
		return null;
	}

	public function get_value_from_item( $value ) {
		// Passed through untouched (a password is not text to sanitise) and only ever
		// read by the Register member action from the raw payload.
		return is_string( $value ) ? $value : '';
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_Option_Type_Form_Builder_Item_Member_Username' );
FW_Option_Type_Builder::register_item_type( 'FW_Option_Type_Form_Builder_Item_Member_Password' );
