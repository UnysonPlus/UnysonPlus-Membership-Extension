<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Member emails: every lifecycle email, editable (Membership → Emails) with
 * placeholders, each one switchable, sent through wp_mail() so the Mailer
 * extension's transport applies. They listen to the membership events; nothing
 * else sends them.
 */
class FW_Membership_Emails {

	const OPTION = 'fw_ext_membership_emails';

	public static function init() {
		add_action( 'unysonplus_membership_joined', array( __CLASS__, '_on_joined' ), 20, 2 );
		add_action( 'unysonplus_membership_cancelled', array( __CLASS__, '_on_cancelled' ), 20, 2 );
		add_action( 'unysonplus_membership_expired', array( __CLASS__, '_on_expired' ), 20, 2 );
		add_action( 'unysonplus_membership_awaiting_approval', array( __CLASS__, '_on_awaiting' ) );
	}

	/**
	 * The emails, with their default wording.
	 *
	 * @return array key => { label, to (member|admin), when, subject, body, enabled }
	 */
	public static function catalogue() {
		/**
		 * Filters the member emails: modules add theirs (key => { label, to, when, subject, body }).
		 */
		return (array) apply_filters( 'unysonplus_membership_email_catalogue', array(
			'verify'         => array(
				'label'   => __( 'Confirm your email', 'fw' ),
				'to'      => 'member',
				'when'    => __( 'Right after someone signs up, when the sign-up form asks for email confirmation. Cannot be switched off while confirmation is on.', 'fw' ),
				'subject' => __( 'Confirm your email for {site_name}', 'fw' ),
				'body'    => __( "Hi {first_name},\n\nThanks for joining {site_name}. Please confirm your email address to finish setting up your account:\n\n{verify_url}\n\nThis link works once and for {verify_days} days. If you did not sign up, you can ignore this email.", 'fw' ),
			),
			'welcome'        => array(
				'label'   => __( 'Welcome', 'fw' ),
				'to'      => 'member',
				'when'    => __( 'When a membership starts: after sign-up (and confirmation), a purchase or a gift. Members you add by hand get it only if you ask.', 'fw' ),
				'subject' => __( 'Welcome to {plan} at {site_name}', 'fw' ),
				'body'    => __( "Hi {first_name},\n\nYour {plan} membership at {site_name} has started.\n\nYour account: {account_url}\n\nSee you inside!", 'fw' ),
			),
			'awaiting'       => array(
				'label'   => __( 'Waiting for approval', 'fw' ),
				'to'      => 'member',
				'when'    => __( 'When a sign-up needs an administrator to approve it.', 'fw' ),
				'subject' => __( 'Your {site_name} account is waiting for approval', 'fw' ),
				'body'    => __( "Hi {first_name},\n\nThanks for signing up to {site_name}. Your account is waiting for approval; we will email you as soon as it is approved.", 'fw' ),
			),
			'admin_awaiting' => array(
				'label'   => __( 'New sign-up to approve (to you)', 'fw' ),
				'to'      => 'admin',
				'when'    => __( 'To the site administrator when a sign-up waits for approval (Settings decides who receives it).', 'fw' ),
				'subject' => __( '[{site_name}] New sign-up to approve: {display_name}', 'fw' ),
				'body'    => __( "{display_name} ({email}) signed up and is waiting for approval.\n\nReview them: {member_admin_url}", 'fw' ),
			),
			'approved'       => array(
				'label'   => __( 'Approved', 'fw' ),
				'to'      => 'member',
				'when'    => __( 'When an administrator approves a sign-up.', 'fw' ),
				'subject' => __( 'Your {site_name} account is approved', 'fw' ),
				'body'    => __( "Hi {first_name},\n\nGood news: your account at {site_name} is approved. You can log in now:\n\n{login_url}", 'fw' ),
			),
			'cancelled'      => array(
				'label'   => __( 'Cancelled', 'fw' ),
				'to'      => 'member',
				'when'    => __( 'When a membership is cancelled and access continues until its end date.', 'fw' ),
				'subject' => __( 'Your {plan} membership is cancelled', 'fw' ),
				'body'    => __( "Hi {first_name},\n\nYour {plan} membership at {site_name} is cancelled. You keep access until {ends_at}.\n\nChanged your mind? {account_url}", 'fw' ),
			),
			'expired'        => array(
				'label'   => __( 'Ended', 'fw' ),
				'to'      => 'member',
				'when'    => __( 'When a membership ends and no longer gives access.', 'fw' ),
				'subject' => __( 'Your {plan} membership has ended', 'fw' ),
				'body'    => __( "Hi {first_name},\n\nYour {plan} membership at {site_name} has ended. We would love to have you back: {account_url}", 'fw' ),
			),
		) );
	}

	/**
	 * The catalogue merged with what the site saved.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = (array) get_option( self::OPTION, array() );
		$out   = array();
		foreach ( self::catalogue() as $key => $email ) {
			$s            = (array) ( $saved[ $key ] ?? array() );
			$email['enabled'] = array_key_exists( 'enabled', $s ) ? (bool) $s['enabled'] : true;
			$email['subject'] = '' !== trim( (string) ( $s['subject'] ?? '' ) ) ? (string) $s['subject'] : $email['subject'];
			$email['body']    = '' !== trim( (string) ( $s['body'] ?? '' ) ) ? (string) $s['body'] : $email['body'];
			$out[ $key ]      = $email;
		}
		return $out;
	}

	/**
	 * @param array $values key => { enabled, subject, body }
	 */
	public static function save( array $values ) {
		$out = array();
		foreach ( self::catalogue() as $key => $email ) {
			$v           = (array) ( $values[ $key ] ?? array() );
			$out[ $key ] = array(
				'enabled' => 'verify' === $key ? true : ! empty( $v['enabled'] ),
				'subject' => sanitize_text_field( (string) ( $v['subject'] ?? '' ) ),
				'body'    => sanitize_textarea_field( (string) ( $v['body'] ?? '' ) ),
			);
		}
		update_option( self::OPTION, $out, false );
	}

	/**
	 * @return array placeholder => description
	 */
	public static function placeholders() {
		return array(
			'{first_name}'       => __( 'first name (or display name)', 'fw' ),
			'{display_name}'     => __( 'display name', 'fw' ),
			'{email}'            => __( 'email address', 'fw' ),
			'{plan}'             => __( 'plan name', 'fw' ),
			'{ends_at}'          => __( 'end date', 'fw' ),
			'{site_name}'        => __( 'site name', 'fw' ),
			'{site_url}'         => __( 'site address', 'fw' ),
			'{login_url}'        => __( 'login page', 'fw' ),
			'{account_url}'      => __( 'member account page', 'fw' ),
			'{verify_url}'       => __( 'confirmation link (Confirm your email only)', 'fw' ),
			'{member_admin_url}' => __( 'the member in wp-admin (admin emails)', 'fw' ),
			'{amount}'           => __( 'amount paid (receipt)', 'fw' ),
			'{payment_date}'     => __( 'payment date (receipt)', 'fw' ),
			'{payment_ref}'      => __( 'payment reference (receipt)', 'fw' ),
		);
	}

	/**
	 * Send one email.
	 *
	 * @param string $key
	 * @param int    $user_id
	 * @param array  $vars    Extra placeholder values (plan, ends_at, verify_url…).
	 * @param bool   $force   Send even if switched off (test sends).
	 * @param string $to      Override the recipient (test sends).
	 *
	 * @return bool
	 */
	public static function send( $key, $user_id, array $vars = array(), $force = false, $to = '' ) {
		$all   = self::all();
		$email = $all[ $key ] ?? null;
		$user  = get_userdata( (int) $user_id );
		if ( ! $email || ! $user || ( ! $email['enabled'] && ! $force ) ) {
			return false;
		}
		$to = '' !== $to ? $to : ( 'admin' === $email['to'] ? FW_Membership_Settings::admin_email() : $user->user_email );
		/** More placeholder values for an email ({update_card_url} from the Retention module…). */
		$vars = (array) apply_filters( 'unysonplus_membership_email_vars', $vars, $key, $user );

		$values = array_merge( array(
			'first_name'       => '' !== (string) $user->first_name ? $user->first_name : $user->display_name,
			'display_name'     => $user->display_name,
			'email'            => $user->user_email,
			'plan'             => '',
			'ends_at'          => '',
			'site_name'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'         => home_url( '/' ),
			'login_url'        => FW_Membership_Settings::login_url(),
			'account_url'      => FW_Membership_Settings::account_url(),
			'verify_url'       => '',
			'verify_days'      => '',
			'member_admin_url' => admin_url( 'admin.php?page=fw-membership&tab=members&member=' . (int) $user->ID ),
		), $vars );

		$map = array();
		foreach ( $values as $k => $v ) {
			$map[ '{' . $k . '}' ] = (string) $v;
		}
		$subject = strtr( (string) $email['subject'], $map );
		$body    = strtr( (string) $email['body'], $map );

		/** Filters a member email just before it is sent. Return false to stop it. */
		$mail = apply_filters( 'unysonplus_membership_email', array( 'to' => $to, 'subject' => $subject, 'body' => $body, 'key' => $key, 'user_id' => (int) $user->ID ), $values );
		if ( ! is_array( $mail ) ) {
			return false;
		}

		return wp_mail( $mail['to'], $mail['subject'], self::wrap( make_clickable( wpautop( esc_html( $mail['body'] ) ) ) ), array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * A plain, readable HTML frame.
	 *
	 * @param string $inner
	 *
	 * @return string
	 */
	private static function wrap( $inner ) {
		return '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d2327">'
			. '<div style="max-width:560px;margin:0 auto;padding:28px;background:#ffffff;border-radius:10px">' . $inner . '</div>'
			. '<p style="max-width:560px;margin:12px auto 0;font-size:12px;color:#6b7280;text-align:center">' . esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</p>'
			. '</body></html>';
	}

	/**
	 * @param array $m
	 *
	 * @return array
	 */
	private static function vars( array $m ) {
		$plan = FW_Membership_Plans::get( $m['plan_id'] );
		return array(
			'plan'    => $plan ? $plan['name'] : '',
			'ends_at' => $m['ends_at'] ? date_i18n( get_option( 'date_format' ), strtotime( $m['ends_at'] ) ) : __( 'no end date', 'fw' ),
		);
	}

	/** @internal */
	public static function _on_joined( $m, $ctx ) {
		if ( ! empty( $ctx['notify'] ) ) {
			self::send( 'welcome', $m['user_id'], self::vars( $m ) );
		}
	}

	/** @internal */
	public static function _on_cancelled( $m, $ctx ) {
		self::send( 'cancelled', $m['user_id'], self::vars( $m ) );
	}

	/** @internal Only a membership that gave access "ends"; a refund or a never-started one does not. */
	public static function _on_expired( $m, $ctx ) {
		if ( empty( $ctx['replaced'] ) && in_array( (string) ( $ctx['from'] ?? '' ), FW_Membership_Memberships::ACCESS_STATUSES, true ) ) {
			self::send( 'expired', $m['user_id'], self::vars( $m ) );
		}
	}

	/** @internal */
	public static function _on_awaiting( $user_id ) {
		self::send( 'awaiting', $user_id );
		if ( FW_Membership_Settings::get( 'notify_admin' ) ) {
			self::send( 'admin_awaiting', $user_id );
		}
	}
}
