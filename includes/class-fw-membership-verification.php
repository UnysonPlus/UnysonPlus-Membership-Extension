<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Email verification and approval for new accounts.
 *
 * A new member can be held in one of two states (user meta `_fw_mbr_state`):
 *   verify    they must click the link in the confirmation email first;
 *   approval  an administrator must approve them (after verifying, when both apply).
 * While held, their memberships stay `pending` and they cannot log in. When they are
 * through, the memberships start (FW_Membership_Service::activate_pending()).
 *
 * The confirmation link carries a single-use token that expires (Settings → link
 * lifetime). Only a hash of it is stored. Opening the link shows a page with a button
 * that confirms — a bare GET never confirms, because mail scanners open every link in
 * an email and would otherwise confirm addresses on someone's behalf.
 */
class FW_Membership_Verification {

	const META_STATE = '_fw_mbr_state';
	const META_TOKEN = '_fw_mbr_verify';

	public static function init() {
		add_action( 'init', array( __CLASS__, '_action_endpoint' ), 5 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, '_filter_authenticate' ), 30 );
	}

	/**
	 * @param int $user_id
	 *
	 * @return string '' | verify | approval
	 */
	public static function state( $user_id ) {
		return (string) get_user_meta( (int) $user_id, self::META_STATE, true );
	}

	/**
	 * Hold a new account until it is verified and/or approved.
	 *
	 * @param int  $user_id
	 * @param bool $verify
	 * @param bool $approval
	 */
	public static function hold( $user_id, $verify, $approval ) {
		if ( $verify ) {
			update_user_meta( $user_id, self::META_STATE, 'verify' );
			update_user_meta( $user_id, '_fw_mbr_needs_approval', $approval ? 1 : 0 );
			self::send( $user_id );
		} elseif ( $approval ) {
			update_user_meta( $user_id, self::META_STATE, 'approval' );
			do_action( 'unysonplus_membership_awaiting_approval', (int) $user_id );
		}
	}

	/**
	 * Send (or resend) the confirmation email with a fresh link; the old one stops working.
	 *
	 * @param int $user_id
	 *
	 * @return bool
	 */
	public static function send( $user_id ) {
		$raw  = wp_generate_password( 32, false, false );
		$days = (int) FW_Membership_Settings::get( 'verify_days' );
		update_user_meta( $user_id, self::META_TOKEN, array(
			'hash'    => hash_hmac( 'sha256', $raw, wp_salt( 'auth' ) ),
			'expires' => time() + $days * DAY_IN_SECONDS,
		) );
		$url = add_query_arg( array( 'fw_mbr_verify' => $raw, 'u' => (int) $user_id ), home_url( '/' ) );

		return FW_Membership_Emails::send( 'verify', $user_id, array( 'verify_url' => $url, 'verify_days' => $days ) );
	}

	/**
	 * Approve a held account: its memberships start and the member is told.
	 *
	 * @param int $user_id
	 */
	public static function approve( $user_id ) {
		delete_user_meta( $user_id, self::META_STATE );
		delete_user_meta( $user_id, '_fw_mbr_needs_approval' );
		delete_user_meta( $user_id, self::META_TOKEN );
		FW_Membership_Service::activate_pending( $user_id, false );
		FW_Membership_Emails::send( 'approved', $user_id );
	}

	/**
	 * Mark the email confirmed: on to approval, or done.
	 *
	 * @param int $user_id
	 *
	 * @return string The new state ('' or approval).
	 */
	public static function mark_verified( $user_id ) {
		delete_user_meta( $user_id, self::META_TOKEN );
		update_user_meta( $user_id, '_fw_mbr_verified', current_time( 'mysql' ) );
		if ( get_user_meta( $user_id, '_fw_mbr_needs_approval', true ) ) {
			update_user_meta( $user_id, self::META_STATE, 'approval' );
			do_action( 'unysonplus_membership_awaiting_approval', (int) $user_id );
			return 'approval';
		}
		delete_user_meta( $user_id, self::META_STATE );
		FW_Membership_Service::activate_pending( $user_id, true );
		return '';
	}

	/**
	 * @param int    $user_id
	 * @param string $raw
	 *
	 * @return bool
	 */
	private static function token_ok( $user_id, $raw ) {
		$t = get_user_meta( $user_id, self::META_TOKEN, true );
		return is_array( $t ) && ! empty( $t['hash'] ) && (int) ( $t['expires'] ?? 0 ) >= time()
			&& hash_equals( (string) $t['hash'], hash_hmac( 'sha256', (string) $raw, wp_salt( 'auth' ) ) );
	}

	/** @internal The confirmation link: a page with a button (GET), then the confirmation (POST). */
	public static function _action_endpoint() {
		if ( empty( $_REQUEST['fw_mbr_verify'] ) || empty( $_REQUEST['u'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- the token is the credential.
			return;
		}
		$raw     = sanitize_text_field( wp_unslash( $_REQUEST['fw_mbr_verify'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$user_id = (int) $_REQUEST['u']; // phpcs:ignore WordPress.Security.NonceVerification
		$name    = get_bloginfo( 'name' );

		if ( 'verify' !== self::state( $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'fw_mbr_notice', 'verified', FW_Membership_Settings::account_url() ) );
			exit;
		}
		if ( ! self::token_ok( $user_id, $raw ) ) {
			wp_die(
				'<h1>' . esc_html__( 'This link has expired', 'fw' ) . '</h1><p>' . esc_html__( 'Confirmation links work once and for a limited time. Log in to get a new one, or ask the site to send it again.', 'fw' ) . '</p>',
				esc_html( $name ),
				array( 'response' => 410, 'link_url' => esc_url( FW_Membership_Settings::login_url() ), 'link_text' => esc_html__( 'Log in', 'fw' ) )
			);
		}
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$action = esc_url( add_query_arg( array( 'fw_mbr_verify' => $raw, 'u' => $user_id ), home_url( '/' ) ) );
			wp_die(
				'<h1>' . esc_html__( 'Confirm your email', 'fw' ) . '</h1>'
				. '<p>' . esc_html( sprintf( __( 'One click to finish setting up your account on %s.', 'fw' ), $name ) ) . '</p>'
				. '<form method="post" action="' . $action . '"><p><button type="submit" class="button button-primary button-large">' . esc_html__( 'Confirm my email', 'fw' ) . '</button></p></form>',
				esc_html( $name ),
				array( 'response' => 200 )
			);
		}

		$state = self::mark_verified( $user_id );
		if ( '' === $state ) {
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, false );
			$to = (string) FW_Membership_Settings::get( 'after_verify' );
			wp_safe_redirect( add_query_arg( 'fw_mbr_notice', 'verified', '' !== $to ? $to : FW_Membership_Settings::account_url() ) );
			exit;
		}
		wp_die(
			'<h1>' . esc_html__( 'Thank you — your email is confirmed', 'fw' ) . '</h1><p>' . esc_html__( 'Your account now waits for approval. We will email you as soon as it is approved.', 'fw' ) . '</p>',
			esc_html( $name ),
			array( 'response' => 200 )
		);
	}

	/**
	 * @internal Held accounts cannot log in yet; say why.
	 *
	 * @param WP_User|WP_Error $user
	 *
	 * @return WP_User|WP_Error
	 */
	public static function _filter_authenticate( $user ) {
		if ( ! $user instanceof WP_User ) {
			return $user;
		}
		switch ( self::state( $user->ID ) ) {
			case 'verify':
				return new WP_Error( 'fw_mbr_unverified', __( 'Please confirm your email address first: open the link in the email we sent you. It may be in your spam folder.', 'fw' ) );
			case 'approval':
				return new WP_Error( 'fw_mbr_awaiting_approval', __( 'Your account is waiting for approval. We will email you as soon as it is approved.', 'fw' ) );
		}
		return $user;
	}
}
