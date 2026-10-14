<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Page caches must never serve one person's view of a protected page to someone else.
 *
 *  - Every protected page (and every denial) is sent with no-cache headers and the
 *    DONOTCACHEPAGE constant most WordPress page caches honour.
 *  - Members carry an `fw_mbr` cookie holding a hash of their plans, for caches and
 *    CDNs that can vary on a cookie (and to let them bypass caching for members).
 *    It is refreshed whenever the plans someone holds change, and cleared on logout.
 */
class FW_Membership_Cache {

	const COOKIE = 'fw_mbr';

	public static function init() {
		add_action( 'init', array( __CLASS__, '_action_sync_cookie' ), 20 );
		add_action( 'wp_logout', array( __CLASS__, '_action_clear_cookie' ) );
	}

	/** Mark the current response uncacheable. */
	public static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- the constant page caches look for.
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/** @internal Keep the plans cookie in step with what the logged-in person holds. */
	public static function _action_sync_cookie() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || headers_sent() || ! is_user_logged_in() ) {
			return;
		}
		$plans = FW_Membership_Service::user_plans( get_current_user_id() );
		sort( $plans );
		$value = $plans ? substr( wp_hash( implode( ',', $plans ) . '|' . get_current_user_id() ), 0, 16 ) : '';
		$now   = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( $value === $now ) {
			return;
		}
		if ( '' === $value ) {
			self::_action_clear_cookie();
			return;
		}
		setcookie( self::COOKIE, $value, array( 'expires' => 0, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	}

	/** @internal */
	public static function _action_clear_cookie() {
		if ( headers_sent() || ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		setcookie( self::COOKIE, '', array( 'expires' => time() - YEAR_IN_SECONDS, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	}
}
