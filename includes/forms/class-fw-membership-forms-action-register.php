<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * "Register member" — a Forms action (Settings → Actions on a contact form) that turns
 * a sign-up form into account creation:
 *
 *   validate()  before the submission is accepted: the form must have an Email field,
 *               the email must not already have an account, and the plan must be open
 *               and not full. The visitor sees the message; nothing is sent or stored.
 *   run()       creates the WordPress user (Username / Password fields when present,
 *               otherwise a username from the email and a set-password email), gives
 *               the chosen plan, and holds the account for email confirmation and/or
 *               approval, or logs the person in straight away.
 *
 * Someone already logged in who submits the form with their own email simply gets the
 * plan; no second account is made.
 */
class FW_Membership_Forms_Action_Register extends FW_Forms_Action {

	public function get_id() {
		return 'member_register';
	}

	public function get_title() {
		return __( 'Register a member (Membership)', 'fw' );
	}

	public function get_options() {
		$plans = array( '' => __( 'No plan (an account only)', 'fw' ) );
		foreach ( FW_Membership_Plans::all( 'active' ) as $p ) {
			$plans[ (string) $p['id'] ] = $p['name'];
		}
		return array(
			'action_member_register'          => $this->switch_option(
				__( 'Register a member', 'fw' ),
				__( 'Create an account from this form: it needs an Email field; add the Username and Password fields from the Membership group if you want people to choose them.', 'fw' )
			),
			'action_member_register_plan'     => array(
				'type'    => 'select',
				'label'   => __( 'Give this plan', 'fw' ),
				'desc'    => __( 'Usually a free plan. Paid plans are sold through checkout once Payments is on.', 'fw' ),
				'value'   => '',
				'choices' => $plans,
			),
			'action_member_register_confirm'  => $this->switch_option(
				__( 'Ask them to confirm their email', 'fw' ),
				__( 'They get a link to click before they can log in and before the plan starts. Recommended: it stops sign-ups with other people\'s addresses.', 'fw' ),
				true
			),
			'action_member_register_approval' => $this->switch_option(
				__( 'Approve each sign-up yourself', 'fw' ),
				__( 'New accounts wait until you approve them on their member page. You get an email for each.', 'fw' )
			),
			'action_member_register_login'    => $this->switch_option(
				__( 'Log them in straight away', 'fw' ),
				__( 'When no confirmation or approval is needed.', 'fw' ),
				true
			),
		);
	}

	/**
	 * @param array $form
	 *
	 * @return array|null The plan, or null for none.
	 */
	private function plan( array $form ) {
		$id = (int) self::opt( $form, 'action_member_register_plan', 0 );
		return $id ? FW_Membership_Plans::get( $id ) : null;
	}

	public function validate( array $ctx ) {
		$email = (string) $ctx['email'];
		if ( '' === $email || ! is_email( $email ) ) {
			return __( 'Please enter a valid email address.', 'fw' );
		}
		if ( is_user_logged_in() ) {
			if ( strtolower( wp_get_current_user()->user_email ) !== strtolower( $email ) ) {
				return __( 'You are already logged in with another email address. Log out first to create a new account.', 'fw' );
			}
		} elseif ( email_exists( $email ) ) {
			return __( 'An account with this email already exists. Please log in instead, or reset your password if you have forgotten it.', 'fw' );
		}
		$plan = $this->plan( $ctx['form'] );
		if ( (int) self::opt( $ctx['form'], 'action_member_register_plan', 0 ) && ( ! $plan || 'active' !== $plan['status'] ) ) {
			return __( 'Sorry, this membership is not open for sign-ups right now.', 'fw' );
		}
		if ( $plan && $plan['signup_limit'] && FW_Membership_Memberships::count( array( 'plan_id' => $plan['id'], 'status' => 'access' ) ) >= $plan['signup_limit'] ) {
			return __( 'Sorry, this membership is full.', 'fw' );
		}
		return null;
	}

	public function run( array $ctx ) {
		$form  = $ctx['form'];
		$email = sanitize_email( (string) $ctx['email'] );
		$plan  = $this->plan( $form );

		// Already logged in with this email: just the plan.
		if ( is_user_logged_in() && strtolower( wp_get_current_user()->user_email ) === strtolower( $email ) ) {
			if ( $plan ) {
				$r = FW_Membership_Service::grant( get_current_user_id(), $plan['id'], array( 'source' => 'registration', 'source_ref' => 'form:' . $ctx['form_id'] ) );
				return is_wp_error( $r ) && 'fw_mbr_already' !== $r->get_error_code() ? $r : null;
			}
			return null;
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'fw_mbr_exists', 'Email already registered (race with another sign-up).' );
		}

		$payload  = (array) $ctx['payload'];
		$username = (string) FW_Membership_Form_Fields::value_of_type( $payload, 'member-username' );
		$password = FW_Membership_Form_Fields::value_of_type( $payload, 'member-password' );
		if ( '' === $username || username_exists( $username ) ) {
			$username = self::username_from_email( $email );
		}
		$names = self::names( (array) $ctx['fields'] );
		$own   = is_string( $password ) && '' !== $password;

		$user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => $own ? $password : wp_generate_password( 24, true, true ),
			'first_name'   => $names['first'],
			'last_name'    => $names['last'],
			'display_name' => trim( $names['first'] . ' ' . $names['last'] ) ? trim( $names['first'] . ' ' . $names['last'] ) : $username,
			'role'         => get_option( 'default_role', 'subscriber' ),
		) );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		update_user_meta( $user_id, '_fw_mbr_registered_via', 'form:' . $ctx['form_id'] );
		FW_Membership_Profile::fill_from_form( $user_id, (array) $ctx['fields'] );

		$confirm  = 'yes' === self::opt( $form, 'action_member_register_confirm', 'yes' );
		$approval = 'yes' === self::opt( $form, 'action_member_register_approval', 'no' );
		$held     = $confirm || $approval;

		if ( $plan ) {
			$r = FW_Membership_Service::grant( $user_id, $plan['id'], array(
				'source'     => 'registration',
				'source_ref' => 'form:' . $ctx['form_id'],
				'status'     => $held ? 'pending' : 'active',
				'notify'     => true,
			) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}

		if ( ! $own ) {
			wp_new_user_notification( $user_id, null, 'user' ); // the set-password email
		}

		if ( $held ) {
			FW_Membership_Verification::hold( $user_id, $confirm, $approval );
		} elseif ( 'yes' === self::opt( $form, 'action_member_register_login', 'yes' ) && ! is_user_logged_in() ) {
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, false );
		}

		/** Fires after Membership created an account from a sign-up form. */
		do_action( 'unysonplus_membership_registered', (int) $user_id, $plan ? (int) $plan['id'] : 0, $ctx );

		return null;
	}

	/**
	 * @param string $email
	 *
	 * @return string An unused username made from the email's local part.
	 */
	public static function username_from_email( $email ) {
		$base = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base = strlen( $base ) >= 3 ? substr( $base, 0, 50 ) : 'member';
		$name = $base;
		$n    = 1;
		while ( username_exists( $name ) ) {
			$name = $base . ( ++$n );
		}
		return $name;
	}

	/**
	 * First and last name from the form's fields, by their labels.
	 *
	 * @param array $fields Entry-style rows.
	 *
	 * @return array { first, last }
	 */
	public static function names( array $fields ) {
		$first = '';
		$last  = '';
		$full  = '';
		foreach ( $fields as $f ) {
			$label = strtolower( (string) ( $f['label'] ?? '' ) );
			$value = is_scalar( $f['value'] ?? null ) ? sanitize_text_field( (string) $f['value'] ) : '';
			if ( '' === $value || in_array( $f['type'] ?? '', array( 'email', 'member-username' ), true ) ) {
				continue;
			}
			if ( '' === $first && preg_match( '/\b(first|given)\b/', $label ) ) {
				$first = $value;
			} elseif ( '' === $last && preg_match( '/\b(last|surname|family)\b/', $label ) ) {
				$last = $value;
			} elseif ( '' === $full && preg_match( '/\bname\b/', $label ) ) {
				$full = $value;
			}
		}
		if ( '' === $first && '' !== $full ) {
			$parts = preg_split( '/\s+/', $full, 2 );
			$first = $parts[0];
			$last  = '' === $last ? (string) ( $parts[1] ?? '' ) : $last;
		}
		return array( 'first' => $first, 'last' => $last );
	}
}
