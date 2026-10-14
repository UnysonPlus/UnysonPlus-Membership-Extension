<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site-wide Membership settings (Membership → Settings), one option.
 */
class FW_Membership_Settings {

	const OPTION = 'fw_ext_membership_settings';

	/** @var array|null */
	private static $cache = null;

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'account_page'    => 0,   // page holding the Member Account element
			'login_page'      => 0,   // page holding the Member Login element (0: wp-login.php)
			'after_verify'    => '',  // where people land after confirming their email ('' = account page)
			'verify_days'     => 7,   // how long a confirmation link works
			'notify_admin'    => 1,   // email the site admin about sign-ups awaiting approval
			'admin_email'     => '',  // '' = the site admin email
			'member_cancel'   => 1,   // members may cancel from their account page
			'button_class'    => 'btn btn-primary', // the theme's button classes (Theme Settings presets)
			'button_class_2'  => 'btn btn-outline', // a second, quieter button
		);
	}

	/**
	 * @param string|null $key
	 *
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			self::$cache = array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
		}
		return null === $key ? self::$cache : ( self::$cache[ $key ] ?? null );
	}

	/**
	 * @param array $values
	 */
	public static function save( array $values ) {
		$d   = self::defaults();
		$out = array(
			'account_page' => max( 0, (int) ( $values['account_page'] ?? 0 ) ),
			'login_page'   => max( 0, (int) ( $values['login_page'] ?? 0 ) ),
			'after_verify' => esc_url_raw( (string) ( $values['after_verify'] ?? '' ) ),
			'verify_days'  => max( 1, min( 30, (int) ( $values['verify_days'] ?? $d['verify_days'] ) ) ),
			'notify_admin' => empty( $values['notify_admin'] ) ? 0 : 1,
			'admin_email'  => sanitize_email( (string) ( $values['admin_email'] ?? '' ) ),
			'member_cancel' => empty( $values['member_cancel'] ) ? 0 : 1,
			'button_class'   => self::clean_classes( (string) ( $values['button_class'] ?? $d['button_class'] ), $d['button_class'] ),
			'button_class_2' => self::clean_classes( (string) ( $values['button_class_2'] ?? $d['button_class_2'] ), $d['button_class_2'] ),
		);
		update_option( self::OPTION, $out, false );
		self::$cache = null;
	}

	/** @return string The member account page, or the WordPress profile. */
	public static function account_url() {
		$page = (int) self::get( 'account_page' );
		return $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : admin_url( 'profile.php' );
	}

	/**
	 * @param string $redirect Where to go after logging in.
	 *
	 * @return string
	 */
	public static function login_url( $redirect = '' ) {
		$page = (int) self::get( 'login_page' );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			return '' !== $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), get_permalink( $page ) ) : get_permalink( $page );
		}
		return wp_login_url( $redirect );
	}

	/**
	 * @param string $classes
	 * @param string $default
	 *
	 * @return string
	 */
	private static function clean_classes( $classes, $default ) {
		$out = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $classes ) ) ) ) );
		return '' !== $out ? $out : $default;
	}

	/**
	 * The theme's button classes for Membership's buttons, so they match the site's
	 * buttons (Theme Settings button presets); `fw-mbr-btn` carries our own fallback
	 * look for themes that do not style `.btn`.
	 *
	 * @param bool $secondary The quieter second button.
	 *
	 * @return string
	 */
	public static function button_class( $secondary = false ) {
		return trim( (string) self::get( $secondary ? 'button_class_2' : 'button_class' ) . ' fw-mbr-btn' );
	}

	/** @return string */
	public static function admin_email() {
		$e = (string) self::get( 'admin_email' );
		return is_email( $e ) ? $e : (string) get_option( 'admin_email' );
	}
}
