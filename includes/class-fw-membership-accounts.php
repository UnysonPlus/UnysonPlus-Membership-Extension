<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Members' accounts on the front end.
 *
 *  - Login goes through WordPress's own login (the Member Login element posts to
 *    wp-login.php), so the Security extension's throttling and two-factor login always
 *    apply. Only where people land afterwards is ours: a plan's "after login" page, or
 *    the member account page, never wp-admin for a member.
 *  - Logout can land on a plan's "after logout" page.
 *  - The Member Account element's forms post back to the page; this class handles them
 *    before any output (profile, email, password, other sessions, cancelling a
 *    membership) and redirects with a notice (post/redirect/get).
 */
class FW_Membership_Accounts {

	const NONCE = 'fw_mbr_account';

	public static function init() {
		add_filter( 'login_redirect', array( __CLASS__, '_filter_login_redirect' ), 20, 3 );
		add_filter( 'logout_redirect', array( __CLASS__, '_filter_logout_redirect' ), 20, 3 );
		add_action( 'template_redirect', array( __CLASS__, '_action_handle' ), 1 );
	}

	/**
	 * The plan that decides a member's redirects: the highest priority one they hold.
	 *
	 * @param int $user_id
	 *
	 * @return array|null
	 */
	public static function main_plan( $user_id ) {
		foreach ( FW_Membership_Service::user_plans( $user_id ) as $pid ) {
			$p = FW_Membership_Plans::get( $pid );
			if ( $p ) {
				return $p;
			}
		}
		return null;
	}

	/** @internal */
	public static function _filter_login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User || user_can( $user, 'edit_posts' ) ) {
			return $redirect_to; // staff keep WordPress's behaviour
		}
		// An explicit destination (back to the page they were reading) wins.
		if ( '' !== (string) $requested && false === strpos( (string) $requested, admin_url() ) ) {
			return $redirect_to;
		}
		$plan = self::main_plan( $user->ID );
		$to   = $plan ? (string) ( $plan['settings']['login_redirect'] ?? '' ) : '';
		return '' !== $to ? $to : FW_Membership_Settings::account_url();
	}

	/** @internal */
	public static function _filter_logout_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User || user_can( $user, 'edit_posts' ) ) {
			return $redirect_to;
		}
		$plan = self::main_plan( $user->ID );
		$to   = $plan ? (string) ( $plan['settings']['logout_redirect'] ?? '' ) : '';
		return '' !== $to ? $to : $redirect_to;
	}

	/* ---------------------------------------------------------------------- *
	 * Account forms
	 * ---------------------------------------------------------------------- */

	/**
	 * @return array code => [ type, message ]
	 */
	public static function notices() {
		/** Notices a module adds for its own account actions: code => [ success|error, message ]. */
		return (array) apply_filters( 'unysonplus_membership_account_notices', array(
			'verified'    => array( 'success', __( 'Your email is confirmed. Welcome!', 'fw' ) ),
			'joined'      => array( 'success', __( 'Welcome! Your membership has started.', 'fw' ) ),
			'profile'     => array( 'success', __( 'Your details are saved.', 'fw' ) ),
			'password'    => array( 'success', __( 'Your password is changed.', 'fw' ) ),
			'sessions'    => array( 'success', __( 'You are signed out everywhere else.', 'fw' ) ),
			'cancelled'   => array( 'success', __( 'Your membership is cancelled. You keep access until its end date.', 'fw' ) ),
			'bad_pass'    => array( 'error', __( 'Your current password is not right.', 'fw' ) ),
			'email_taken' => array( 'error', __( 'Another account already uses that email address.', 'fw' ) ),
			'bad_email'   => array( 'error', __( 'Please enter a valid email address.', 'fw' ) ),
			'mismatch'    => array( 'error', __( 'The two new passwords do not match.', 'fw' ) ),
			'short'       => array( 'error', __( 'The new password must be at least 8 characters.', 'fw' ) ),
			'failed'      => array( 'error', __( 'Something went wrong. Please try again.', 'fw' ) ),
			'fields'      => array( 'error', __( 'Please check your details.', 'fw' ) ),
		) );
	}

	/** @internal */
	public static function _action_handle() {
		if ( empty( $_POST['fw_mbr_account_action'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( self::NONCE );
		$action = sanitize_key( wp_unslash( $_POST['fw_mbr_account_action'] ) );
		$user   = wp_get_current_user();
		$code   = 'failed';

		switch ( $action ) {
			case 'profile':
				$code = self::save_profile( $user );
				break;
			case 'password':
				$code = self::save_password( $user );
				break;
			case 'sessions':
				$manager = WP_Session_Tokens::get_instance( $user->ID );
				$manager->destroy_others( wp_get_session_token() );
				$code = 'sessions';
				break;
			case 'cancel':
				$id = isset( $_POST['membership'] ) ? (int) $_POST['membership'] : 0;
				$m  = $id ? FW_Membership_Memberships::get( $id ) : null;
				if ( $m && (int) $m['user_id'] === (int) $user->ID && FW_Membership_Settings::get( 'member_cancel' ) && in_array( $m['status'], array( 'active', 'trialing' ), true ) ) {
					$r    = FW_Membership_Service::cancel( $id );
					$code = is_wp_error( $r ) ? 'failed' : 'cancelled';
				}
				break;
			default:
				/**
				 * A module's own account action (nonce already checked). Return a notice code,
				 * or redirect and exit yourself.
				 */
				$code = (string) apply_filters( 'unysonplus_membership_account_action', 'failed', $action, $user );
		}

		$back = wp_get_referer();
		$back = $back ? $back : FW_Membership_Settings::account_url();
		wp_safe_redirect( add_query_arg( 'fw_mbr_notice', $code, remove_query_arg( array( 'fw_mbr_notice', 'fw_mbr_leave', 'step' ), $back ) ) . '#fw-mbr-account' );
		exit;
	}

	/**
	 * @param WP_User $user
	 *
	 * @return string notice code
	 */
	private static function save_profile( $user ) {
		// phpcs:disable WordPress.Security.NonceVerification -- checked in _action_handle().
		$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		// Profile fields first: nothing is saved if one of them is not valid.
		$editable = array_filter( FW_Membership_Profile::fields_for_user( $user->ID ), function ( $f ) {
			return $f['member_edit'];
		} );
		if ( $editable ) {
			$r = FW_Membership_Profile::save_values( $user->ID, $editable, FW_Membership_Profile::posted() );
			if ( is_wp_error( $r ) ) {
				set_transient( 'fw_mbr_notice_' . $user->ID, $r->get_error_message(), MINUTE_IN_SECONDS );
				return 'fields';
			}
		}
		$data  = array(
			'ID'           => $user->ID,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => trim( $first . ' ' . $last ) ? trim( $first . ' ' . $last ) : $user->display_name,
		);
		if ( '' !== $email && strtolower( $email ) !== strtolower( $user->user_email ) ) {
			if ( ! is_email( $email ) ) {
				return 'bad_email';
			}
			// Changing the address someone logs in with needs their password.
			if ( ! wp_check_password( (string) wp_unslash( $_POST['current_password'] ?? '' ), $user->user_pass, $user->ID ) ) {
				return 'bad_pass';
			}
			if ( email_exists( $email ) ) {
				return 'email_taken';
			}
			$data['user_email'] = $email;
		}
		// phpcs:enable
		return is_wp_error( wp_update_user( $data ) ) ? 'failed' : 'profile';
	}

	/**
	 * @param WP_User $user
	 *
	 * @return string notice code
	 */
	private static function save_password( $user ) {
		// phpcs:disable WordPress.Security.NonceVerification -- checked in _action_handle().
		$current = (string) wp_unslash( $_POST['current_password'] ?? '' );
		$new     = (string) wp_unslash( $_POST['new_password'] ?? '' );
		$repeat  = (string) wp_unslash( $_POST['new_password_repeat'] ?? '' );
		// phpcs:enable
		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			return 'bad_pass';
		}
		if ( strlen( $new ) < 8 ) {
			return 'short';
		}
		if ( ! hash_equals( $new, $repeat ) ) {
			return 'mismatch';
		}
		// For the current user, wp_update_user() re-issues the login cookie with the new password.
		return is_wp_error( wp_update_user( array( 'ID' => $user->ID, 'user_pass' => $new ) ) ) ? 'failed' : 'password';
	}

	/**
	 * The notice for ?fw_mbr_notice=…, as HTML, or ''.
	 *
	 * @return string
	 */
	public static function notice_html() {
		$code = isset( $_GET['fw_mbr_notice'] ) ? sanitize_key( wp_unslash( $_GET['fw_mbr_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$all  = self::notices();
		if ( ! isset( $all[ $code ] ) ) {
			return '';
		}
		if ( 'error' === $all[ $code ][0] && is_user_logged_in() ) {
			$detail = get_transient( 'fw_mbr_notice_' . get_current_user_id() );
			if ( $detail ) {
				delete_transient( 'fw_mbr_notice_' . get_current_user_id() );
				$all[ $code ][1] = (string) $detail;
			}
		}
		return sprintf( '<p class="fw-mbr-notice fw-mbr-notice--%1$s" role="%2$s">%3$s</p>', esc_attr( $all[ $code ][0] ), 'error' === $all[ $code ][0] ? 'alert' : 'status', esc_html( $all[ $code ][1] ) );
	}

	/**
	 * The login form (posts to wp-login.php) with "lost password" and an optional
	 * register link. Shared by the Member Login and Member Account elements.
	 *
	 * @param array $args redirect ('' = back to this page, 'plan' = the member's plan / account page,
	 *                    or an address), show_remember, show_lost, register_text, register_link
	 *
	 * @return string
	 */
	public static function login_form( array $args = array() ) {
		FW_Membership_Denied::enqueue();
		$here     = ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$redirect = (string) ( $args['redirect'] ?? '' );
		// "Where the plan says": hand WordPress its default (wp-admin), which the
		// login_redirect filter above turns into the plan's page or the account page
		// for members, while staff still land in wp-admin.
		if ( 'plan' === $redirect ) {
			$redirect = empty( $_GET['redirect_to'] ) ? admin_url() : ''; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( '' === $redirect && ! empty( $_GET['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$redirect = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), '' ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$form = wp_login_form( array(
			'echo'           => false,
			'redirect'       => '' !== $redirect ? $redirect : remove_query_arg( array( 'fw_mbr_notice', 'redirect_to' ), $here ),
			'form_id'        => 'fw-mbr-login-' . wp_rand( 100, 99999 ),
			'remember'       => ! isset( $args['show_remember'] ) || $args['show_remember'],
			'label_username' => __( 'Email or username', 'fw' ),
		) );
		// The submit button gets the theme's button classes, like every Membership button.
		$form  = str_replace( 'class="button button-primary"', 'class="button button-primary ' . esc_attr( FW_Membership_Settings::button_class() ) . '"', $form );
		$links = array();
		if ( ! isset( $args['show_lost'] ) || $args['show_lost'] ) {
			$links[] = '<a href="' . esc_url( wp_lostpassword_url( $here ) ) . '">' . esc_html__( 'Forgot your password?', 'fw' ) . '</a>';
		}
		if ( ! empty( $args['register_text'] ) && ! empty( $args['register_link'] ) ) {
			$links[] = '<a href="' . esc_url( $args['register_link'] ) . '">' . esc_html( $args['register_text'] ) . '</a>';
		}
		/** More ways to log in under the form (a login link by email, a passkey). */
		$more = (string) apply_filters( 'unysonplus_membership_login_form_after', '', '' !== $redirect ? $redirect : remove_query_arg( array( 'fw_mbr_notice', 'redirect_to' ), $here ) );
		return '<div class="fw-mbr-login">' . $form . $more . ( $links ? '<p class="fw-mbr-login__links">' . implode( ' <span aria-hidden="true">·</span> ', $links ) . '</p>' : '' ) . '</div>';
	}
}
