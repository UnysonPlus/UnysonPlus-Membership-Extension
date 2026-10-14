<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * One-click login links. A member asks for one with their email (the Member Login
 * element, "Email me a login link"); the answer is the same whether or not the address
 * has an account. The link works once, for 15 minutes; only its hash is stored. Opening
 * it shows a "Log me in" button (a POST), so the mail scanners that open every link
 * cannot use it up. One request per address per minute.
 */
class FW_Membership_Magic_Links {

	const META    = '_fw_mbr_magic';
	const TTL     = 900;
	const NONCE   = 'fw_mbr_magic';
	const REQUEST = 'fw_mbr_magic_request';

	/** @var string The login method for the log on this request. */
	public static $method = '';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, '_request' ), 2 );
		add_action( 'template_redirect', array( __CLASS__, '_link' ), 2 );
		add_filter( 'unysonplus_membership_login_form_after', array( __CLASS__, '_form' ), 10, 2 );
		add_filter( 'unysonplus_membership_email_catalogue', array( __CLASS__, '_emails' ) );
		add_filter( 'unysonplus_membership_login_method', array( __CLASS__, '_method' ) );
		add_filter( 'unysonplus_membership_account_notices', function ( $n ) {
			$n['link_sent'] = array( 'success', __( 'If that address has an account, a login link is on its way. It works once, for 15 minutes.', 'fw' ) );
			return $n;
		} );
	}

	/** @internal */
	public static function _method( $m ) {
		return '' !== self::$method ? self::$method : $m;
	}

	/**
	 * Send a login link (does nothing, silently, for an unknown address).
	 *
	 * @param string $email
	 * @param string $redirect Where to go after logging in.
	 *
	 * @return bool A link was sent.
	 */
	public static function send( $email, $redirect = '' ) {
		$u = get_user_by( 'email', sanitize_email( $email ) );
		if ( ! $u ) {
			return false;
		}
		$key = 'fw_mbr_magic_rate_' . md5( strtolower( $u->user_email ) );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, MINUTE_IN_SECONDS );
		$token = wp_generate_password( 32, false, false );
		update_user_meta( $u->ID, self::META, array( 'hash' => wp_hash_password( $token ), 'expires' => time() + self::TTL, 'redirect' => esc_url_raw( $redirect ) ) );
		$url = add_query_arg( array( 'fw_mbr_magic' => $u->ID . '.' . $token ), FW_Membership_Settings::account_url() );
		FW_Membership_Emails::send( 'login_link', $u->ID, array( 'login_link' => $url, 'minutes' => (string) ( self::TTL / 60 ) ) );
		return true;
	}

	/**
	 * Check a link value without using it.
	 *
	 * @param string $raw "<user id>.<token>"
	 *
	 * @return WP_User|null
	 */
	public static function check( $raw ) {
		$parts = explode( '.', (string) $raw, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$u    = get_userdata( (int) $parts[0] );
		$data = $u ? (array) get_user_meta( $u->ID, self::META, true ) : array();
		if ( ! $u || empty( $data['hash'] ) || (int) $data['expires'] < time() || ! wp_check_password( $parts[1], $data['hash'], $u->ID ) ) {
			return null;
		}
		return $u;
	}

	/** @internal "Email me a login link" was posted. */
	public static function _request() {
		if ( empty( $_POST[ self::REQUEST ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( self::NONCE );
		self::send( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ), esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? '' ) ) );
		$back = wp_get_referer() ? wp_get_referer() : FW_Membership_Settings::login_url();
		wp_safe_redirect( add_query_arg( 'fw_mbr_notice', 'link_sent', $back ) );
		exit;
	}

	/** @internal Opening the link shows a button; pressing it logs in. */
	public static function _link() {
		if ( ! isset( $_GET['fw_mbr_magic'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$raw = sanitize_text_field( wp_unslash( $_GET['fw_mbr_magic'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$u   = self::check( $raw );
		if ( $u && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && check_admin_referer( self::NONCE ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$data = (array) get_user_meta( $u->ID, self::META, true );
			delete_user_meta( $u->ID, self::META ); // single use
			self::$method = 'login_link';
			wp_set_current_user( $u->ID );
			wp_set_auth_cookie( $u->ID, false );
			do_action( 'wp_login', $u->user_login, $u );
			$to = ! empty( $data['redirect'] ) ? $data['redirect'] : FW_Membership_Settings::account_url();
			wp_safe_redirect( $to );
			exit;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><meta name="robots" content="noindex, nofollow" />
<title><?php esc_html_e( 'Log in', 'fw' ); ?></title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:17px/1.5 system-ui,sans-serif;background:#f3f4f6;color:#111827;padding:24px;box-sizing:border-box;text-align:center}main{max-width:420px;background:#fff;padding:32px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08)}button{font:inherit;padding:.7rem 1.6rem;border-radius:8px;border:0;background:#111827;color:#fff;cursor:pointer}a{color:inherit}</style>
</head><body><main>
	<?php if ( $u ) : ?>
		<h1><?php echo esc_html( sprintf( __( 'Log in to %s', 'fw' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) ); ?></h1>
		<p><?php echo esc_html( sprintf( __( 'As %s.', 'fw' ), $u->user_email ) ); ?></p>
		<form method="post"><?php wp_nonce_field( self::NONCE ); ?><button type="submit"><?php esc_html_e( 'Log me in', 'fw' ); ?></button></form>
	<?php else : ?>
		<h1><?php esc_html_e( 'This link has expired', 'fw' ); ?></h1>
		<p><?php esc_html_e( 'Login links work once, for 15 minutes.', 'fw' ); ?> <a href="<?php echo esc_url( FW_Membership_Settings::login_url() ); ?>"><?php esc_html_e( 'Ask for a new one', 'fw' ); ?></a></p>
	<?php endif; ?>
</main></body></html>
		<?php
		exit;
	}

	/** @internal "Email me a login link" under the login form. */
	public static function _form( $html, $redirect ) {
		ob_start();
		?>
		<details class="fw-mbr-magic">
			<summary><?php esc_html_e( 'Email me a login link instead', 'fw' ); ?></summary>
			<form method="post" class="fw-mbr-form">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::REQUEST ); ?>" value="1" />
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( (string) $redirect ); ?>" />
				<p class="fw-mbr-form__row"><label for="fw-mbr-magic-email"><?php esc_html_e( 'Your email', 'fw' ); ?></label><input type="email" id="fw-mbr-magic-email" name="email" autocomplete="email" required /></p>
				<p class="fw-mbr-form__actions"><button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class( true ) ); ?>"><?php esc_html_e( 'Send the link', 'fw' ); ?></button></p>
			</form>
		</details>
		<?php
		return $html . ob_get_clean();
	}

	/** @internal */
	public static function _emails( $c ) {
		$c['login_link'] = array(
			'label'   => __( 'Login link', 'fw' ),
			'to'      => 'member',
			'when'    => __( 'When a member asks for a one-click login link.', 'fw' ),
			'subject' => __( 'Your login link for {site_name}', 'fw' ),
			'body'    => __( "Hi {first_name},\n\nHere is your link to log in. It works once, for {minutes} minutes:\n\n{login_link}\n\nIf you did not ask for it, you can ignore this email.", 'fw' ),
		);
		return $c;
	}
}
