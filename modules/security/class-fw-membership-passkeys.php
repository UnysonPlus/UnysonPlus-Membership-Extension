<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Passkeys (WebAuthn): sign in with a fingerprint, face or device PIN instead of a
 * password. Members add passkeys on their account page; the login form gets "Log in with
 * a passkey". No library: a small CBOR reader, COSE keys (ES256 P-256 and RS256) turned
 * into PEM for OpenSSL.
 *
 *   REST (namespace fw-membership/v1):
 *     POST passkeys/register/options   (logged in) creation options + a challenge
 *     POST passkeys/register           (logged in) verify and store the new passkey
 *     POST passkeys/login/options      a challenge (id + value), no account named
 *     POST passkeys/login              verify the signature, log in
 *
 *   Checked every time: the challenge (one use, 5 minutes), the origin (this site), the
 *   relying party id hash (this host), the user-present flag, the signature, and that the
 *   sign counter moves forward (a cloned authenticator would repeat it).
 *
 * A passkey is two factors in one (something you have, unlocked by something you are or
 * know), so a passkey login is not asked for the Security extension's second step.
 * User meta: _fw_mbr_passkeys (list), _fw_mbr_passkey_handle (the WebAuthn user handle).
 */
class FW_Membership_Passkeys {

	const META   = '_fw_mbr_passkeys';
	const HANDLE = '_fw_mbr_passkey_handle';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, '_routes' ) );
		add_action( 'unysonplus_membership_account_sections', array( __CLASS__, '_account' ), 41 );
		add_filter( 'unysonplus_membership_account_action', array( __CLASS__, '_handle' ), 10, 3 );
		add_filter( 'unysonplus_membership_login_form_after', array( __CLASS__, '_login_button' ), 5, 2 );
		add_action( 'login_form', array( __CLASS__, '_wp_login_button' ) );
		add_action( 'unysonplus_membership_member_side_panels', array( __CLASS__, '_admin_panel' ) );
		add_filter( 'unysonplus_membership_account_notices', function ( $n ) {
			$n['passkey_removed'] = array( 'success', __( 'Passkey removed.', 'fw' ) );
			return $n;
		} );
	}

	/* ---------------------------------------------------------------------- *
	 * Encoding helpers
	 * ---------------------------------------------------------------------- */

	public static function b64u_encode( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	public static function b64u_decode( $s ) {
		$s = strtr( (string) $s, '-_', '+/' );
		return (string) base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Read one CBOR item.
	 *
	 * @param string $data
	 * @param int    $pos  Moved past the item.
	 *
	 * @return mixed
	 * @throws Exception On malformed data.
	 */
	public static function cbor( $data, &$pos = 0 ) {
		if ( $pos >= strlen( $data ) ) {
			throw new Exception( 'cbor: end of data' );
		}
		$b     = ord( $data[ $pos++ ] );
		$major = $b >> 5;
		$info  = $b & 31;
		$len   = self::cbor_len( $data, $pos, $info );
		switch ( $major ) {
			case 0:
				return $len;
			case 1:
				return -1 - $len;
			case 2:
			case 3:
				$v    = substr( $data, $pos, $len );
				$pos += $len;
				return $v;
			case 4:
				$out = array();
				for ( $i = 0; $i < $len; $i++ ) {
					$out[] = self::cbor( $data, $pos );
				}
				return $out;
			case 5:
				$out = array();
				for ( $i = 0; $i < $len; $i++ ) {
					$k         = self::cbor( $data, $pos );
					$out[ $k ] = self::cbor( $data, $pos );
				}
				return $out;
			case 6:
				return self::cbor( $data, $pos ); // a tag: take the tagged item
			case 7:
				if ( 20 === $info ) {
					return false;
				}
				if ( 21 === $info ) {
					return true;
				}
				return null;
		}
		throw new Exception( 'cbor: bad item' );
	}

	private static function cbor_len( $data, &$pos, $info ) {
		if ( $info < 24 ) {
			return $info;
		}
		$n = array( 24 => 1, 25 => 2, 26 => 4, 27 => 8 )[ $info ] ?? 0;
		if ( ! $n ) {
			throw new Exception( 'cbor: length' );
		}
		$v = 0;
		for ( $i = 0; $i < $n; $i++ ) {
			$v = ( $v << 8 ) | ord( $data[ $pos++ ] );
		}
		return $v;
	}

	private static function der_len( $n ) {
		if ( $n < 128 ) {
			return chr( $n );
		}
		$b = ltrim( pack( 'N', $n ), "\0" );
		return chr( 0x80 | strlen( $b ) ) . $b;
	}

	private static function der( $tag, $content ) {
		return chr( $tag ) . self::der_len( strlen( $content ) ) . $content;
	}

	/**
	 * A COSE public key as PEM.
	 *
	 * @param array $cose
	 *
	 * @return array|null { pem, alg }
	 */
	public static function cose_to_pem( array $cose ) {
		$kty = $cose[1] ?? null;
		$alg = $cose[3] ?? null;
		if ( 2 === $kty && -7 === $alg && 1 === ( $cose[-1] ?? null ) && 32 === strlen( (string) ( $cose[-2] ?? '' ) ) && 32 === strlen( (string) ( $cose[-3] ?? '' ) ) ) {
			$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . "\x04" . $cose[-2] . $cose[-3];
			$type = 'ES256';
		} elseif ( 3 === $kty && -257 === $alg && ! empty( $cose[-1] ) && ! empty( $cose[-2] ) ) {
			$int = function ( $bin ) {
				$bin = ltrim( $bin, "\0" );
				if ( ord( $bin[0] ) & 0x80 ) {
					$bin = "\0" . $bin;
				}
				return self::der( 0x02, $bin );
			};
			$rsa  = self::der( 0x30, $int( $cose[-1] ) . $int( $cose[-2] ) );
			$der  = self::der( 0x30, self::der( 0x30, hex2bin( '06092a864886f70d0101010500' ) ) . self::der( 0x03, "\0" . $rsa ) );
			$type = 'RS256';
		} else {
			return null;
		}
		return array( 'pem' => "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n", 'alg' => $type ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/* ---------------------------------------------------------------------- *
	 * Site identity, challenges, storage
	 * ---------------------------------------------------------------------- */

	/** @return string The relying party id (this site's host). */
	public static function rp_id() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/** @return string This site's origin (scheme://host[:port]). */
	public static function origin() {
		$p = wp_parse_url( home_url() );
		return $p['scheme'] . '://' . $p['host'] . ( ! empty( $p['port'] ) ? ':' . $p['port'] : '' );
	}

	private static function new_challenge( $key ) {
		$c = random_bytes( 32 );
		set_transient( 'fw_mbr_pk_' . $key, self::b64u_encode( $c ), 5 * MINUTE_IN_SECONDS );
		return $c;
	}

	private static function take_challenge( $key ) {
		$v = get_transient( 'fw_mbr_pk_' . $key );
		delete_transient( 'fw_mbr_pk_' . $key ); // one use
		return is_string( $v ) ? $v : '';
	}

	/** @return array[] A user's passkeys. */
	public static function all( $user_id ) {
		$v = get_user_meta( (int) $user_id, self::META, true );
		// No passkeys yet reads back as '' (not an array): never let that become a "key".
		return is_array( $v ) ? array_values( array_filter( $v, function ( $k ) {
			return is_array( $k ) && ! empty( $k['id'] ) && ! empty( $k['pem'] );
		} ) ) : array();
	}

	private static function save_all( $user_id, array $keys ) {
		update_user_meta( (int) $user_id, self::META, array_values( $keys ) );
	}

	/** @return string The user's WebAuthn user handle (random, made once). */
	public static function handle( $user_id ) {
		$h = (string) get_user_meta( (int) $user_id, self::HANDLE, true );
		if ( '' === $h ) {
			$h = self::b64u_encode( random_bytes( 32 ) );
			update_user_meta( (int) $user_id, self::HANDLE, $h );
		}
		return $h;
	}

	/**
	 * The parts of authenticator data.
	 *
	 * @param string $auth
	 *
	 * @return array { rp_hash, flags, count, cred_id?, cose? }
	 * @throws Exception On malformed data.
	 */
	public static function parse_auth_data( $auth ) {
		if ( strlen( $auth ) < 37 ) {
			throw new Exception( 'authData too short' );
		}
		$out = array( 'rp_hash' => substr( $auth, 0, 32 ), 'flags' => ord( $auth[32] ), 'count' => unpack( 'N', substr( $auth, 33, 4 ) )[1] );
		if ( $out['flags'] & 0x40 ) { // attested credential data
			$len            = unpack( 'n', substr( $auth, 53, 2 ) )[1];
			$out['cred_id'] = substr( $auth, 55, $len );
			$pos            = 55 + $len;
			$out['cose']    = self::cbor( $auth, $pos );
		}
		return $out;
	}

	/**
	 * Check clientDataJSON.
	 *
	 * @return true|WP_Error
	 */
	private static function check_client( $json, $type, $challenge_b64u ) {
		$c = json_decode( (string) $json, true );
		if ( ! is_array( $c ) || $type !== ( $c['type'] ?? '' ) ) {
			return new WP_Error( 'fw_mbr_pk', __( 'The passkey answer was not what was asked for.', 'fw' ) );
		}
		if ( '' === $challenge_b64u || ! hash_equals( $challenge_b64u, (string) ( $c['challenge'] ?? '' ) ) ) {
			return new WP_Error( 'fw_mbr_pk', __( 'The passkey request expired. Please try again.', 'fw' ) );
		}
		if ( self::origin() !== (string) ( $c['origin'] ?? '' ) ) {
			return new WP_Error( 'fw_mbr_pk', __( 'The passkey was used from another site.', 'fw' ) );
		}
		return true;
	}

	/* ---------------------------------------------------------------------- *
	 * Registration and login
	 * ---------------------------------------------------------------------- */

	/** @return array Options for navigator.credentials.create() (binary as base64url). */
	public static function register_options( $user_id ) {
		$u = get_userdata( $user_id );
		return array(
			'challenge'              => self::b64u_encode( self::new_challenge( 'reg_' . $user_id ) ),
			'rp'                     => array( 'id' => self::rp_id(), 'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'user'                   => array( 'id' => self::handle( $user_id ), 'name' => $u->user_email, 'displayName' => $u->display_name ),
			'pubKeyCredParams'       => array( array( 'type' => 'public-key', 'alg' => -7 ), array( 'type' => 'public-key', 'alg' => -257 ) ),
			'timeout'                => 120000,
			'attestation'            => 'none',
			'authenticatorSelection' => array( 'residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'preferred' ),
			'excludeCredentials'     => array_map( function ( $k ) {
				return array( 'type' => 'public-key', 'id' => $k['id'] );
			}, self::all( $user_id ) ),
		);
	}

	/**
	 * Verify and store a new passkey.
	 *
	 * @param int   $user_id
	 * @param array $r { id, clientDataJSON, attestationObject, name } (binary as base64url)
	 *
	 * @return true|WP_Error
	 */
	public static function register( $user_id, array $r ) {
		$client = self::b64u_decode( $r['clientDataJSON'] ?? '' );
		$ok     = self::check_client( $client, 'webauthn.create', self::take_challenge( 'reg_' . $user_id ) );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		try {
			$pos = 0;
			$att = self::cbor( self::b64u_decode( $r['attestationObject'] ?? '' ), $pos );
			$ad  = self::parse_auth_data( (string) ( $att['authData'] ?? '' ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'fw_mbr_pk', __( 'The passkey could not be read.', 'fw' ) );
		}
		if ( ! hash_equals( hash( 'sha256', self::rp_id(), true ), $ad['rp_hash'] ) || ! ( $ad['flags'] & 0x01 ) || empty( $ad['cred_id'] ) ) {
			return new WP_Error( 'fw_mbr_pk', __( 'The passkey is not for this site.', 'fw' ) );
		}
		$key = self::cose_to_pem( (array) $ad['cose'] );
		if ( ! $key ) {
			return new WP_Error( 'fw_mbr_pk', __( 'This kind of passkey is not supported.', 'fw' ) );
		}
		$keys   = self::all( $user_id );
		$keys[] = array(
			'id'         => self::b64u_encode( $ad['cred_id'] ),
			'pem'        => $key['pem'],
			'alg'        => $key['alg'],
			'count'      => (int) $ad['count'],
			'name'       => sanitize_text_field( (string) ( $r['name'] ?? '' ) ) ?: FW_Membership_Log::agent() ?: __( 'Passkey', 'fw' ),
			'created'    => current_time( 'mysql' ),
			'last_used'  => '',
		);
		self::save_all( $user_id, $keys );
		return true;
	}

	/** @return array { id, options } for navigator.credentials.get(). */
	public static function login_options() {
		$id = wp_generate_password( 20, false, false );
		return array(
			'id'      => $id,
			'options' => array(
				'challenge'        => self::b64u_encode( self::new_challenge( 'login_' . $id ) ),
				'rpId'             => self::rp_id(),
				'timeout'          => 120000,
				'userVerification' => 'preferred',
				'allowCredentials' => array(),
			),
		);
	}

	/**
	 * Verify a passkey sign-in.
	 *
	 * @param array $r { challenge_id, id, clientDataJSON, authenticatorData, signature, userHandle }
	 *
	 * @return int|WP_Error The user id.
	 */
	public static function verify_login( array $r ) {
		$client = self::b64u_decode( $r['clientDataJSON'] ?? '' );
		$ok     = self::check_client( $client, 'webauthn.get', self::take_challenge( 'login_' . preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $r['challenge_id'] ?? '' ) ) ) );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$handle = (string) ( $r['userHandle'] ?? '' );
		$users  = '' !== $handle ? get_users( array( 'meta_key' => self::HANDLE, 'meta_value' => $handle, 'number' => 1, 'fields' => 'ID' ) ) : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
		$uid    = $users ? (int) $users[0] : 0;
		$keys   = $uid ? self::all( $uid ) : array();
		$idx    = null;
		foreach ( $keys as $i => $k ) {
			if ( hash_equals( $k['id'], (string) ( $r['id'] ?? '' ) ) ) {
				$idx = $i;
			}
		}
		if ( null === $idx ) {
			return new WP_Error( 'fw_mbr_pk', __( 'This passkey is not linked to an account here.', 'fw' ) );
		}
		$auth = self::b64u_decode( $r['authenticatorData'] ?? '' );
		try {
			$ad = self::parse_auth_data( $auth );
		} catch ( Exception $e ) {
			return new WP_Error( 'fw_mbr_pk', __( 'The passkey could not be read.', 'fw' ) );
		}
		if ( ! hash_equals( hash( 'sha256', self::rp_id(), true ), $ad['rp_hash'] ) || ! ( $ad['flags'] & 0x01 ) ) {
			return new WP_Error( 'fw_mbr_pk', __( 'The passkey is not for this site.', 'fw' ) );
		}
		$signed = $auth . hash( 'sha256', $client, true );
		if ( 1 !== openssl_verify( $signed, self::b64u_decode( $r['signature'] ?? '' ), $keys[ $idx ]['pem'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'fw_mbr_pk', __( 'The passkey signature did not match.', 'fw' ) );
		}
		$stored = (int) $keys[ $idx ]['count'];
		if ( ( $ad['count'] || $stored ) && $ad['count'] <= $stored ) {
			return new WP_Error( 'fw_mbr_pk', __( 'This passkey looks copied. Remove it from your account and add it again.', 'fw' ) );
		}
		$keys[ $idx ]['count']     = (int) $ad['count'];
		$keys[ $idx ]['last_used'] = current_time( 'mysql' );
		self::save_all( $uid, $keys );
		return $uid;
	}

	/* ---------------------------------------------------------------------- *
	 * REST
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _routes() {
		$ns = FW_Membership_REST::NS;
		register_rest_route( $ns, '/passkeys/register/options', array( 'methods' => 'POST', 'permission_callback' => 'is_user_logged_in', 'callback' => function () {
			return self::register_options( get_current_user_id() );
		} ) );
		register_rest_route( $ns, '/passkeys/register', array( 'methods' => 'POST', 'permission_callback' => 'is_user_logged_in', 'callback' => function ( WP_REST_Request $q ) {
			$r = self::register( get_current_user_id(), (array) $q->get_json_params() );
			return is_wp_error( $r ) ? new WP_Error( $r->get_error_code(), $r->get_error_message(), array( 'status' => 400 ) ) : array( 'ok' => true );
		} ) );
		register_rest_route( $ns, '/passkeys/login/options', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function () {
			return self::login_options();
		} ) );
		register_rest_route( $ns, '/passkeys/login', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ( WP_REST_Request $q ) {
			$p   = (array) $q->get_json_params();
			$uid = self::verify_login( $p );
			if ( is_wp_error( $uid ) ) {
				return new WP_Error( $uid->get_error_code(), $uid->get_error_message(), array( 'status' => 400 ) );
			}
			$u = get_userdata( $uid );
			FW_Membership_Magic_Links::$method = 'login_passkey';
			wp_set_current_user( $uid );
			wp_set_auth_cookie( $uid, true );
			do_action( 'wp_login', $u->user_login, $u );
			$asked = wp_validate_redirect( esc_url_raw( (string) ( $p['redirect'] ?? '' ) ), '' );
			// The same destination a password login gets (members to their plan's page or account).
			$to = apply_filters( 'login_redirect', '' !== $asked ? $asked : admin_url(), $asked, $u );
			return array( 'ok' => true, 'redirect' => wp_validate_redirect( (string) $to, FW_Membership_Settings::account_url() ) );
		} ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Screens
	 * ---------------------------------------------------------------------- */

	private static function enqueue() {
		wp_enqueue_script( 'fw-mbr-passkeys', fw_ext( 'membership' )->get_uri( '/static/js/passkeys.js' ), array(), fw_ext( 'membership' )->manifest->get_version(), true );
		wp_localize_script( 'fw-mbr-passkeys', 'fwMbrPasskeys', array(
			'root'    => esc_url_raw( rest_url( FW_Membership_REST::NS . '/passkeys/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'texts'   => array(
				'unsupported' => __( 'This browser cannot use passkeys.', 'fw' ),
				'failed'      => __( 'That did not work. Please try again.', 'fw' ),
				'added'       => __( 'Passkey added.', 'fw' ),
			),
		) );
	}

	/** @internal */
	public static function _login_button( $html, $redirect ) {
		self::enqueue();
		return $html . '<p class="fw-mbr-passkey-login" hidden><button type="button" class="' . esc_attr( FW_Membership_Settings::button_class( true ) ) . '" data-fw-mbr-passkey-login data-redirect="' . esc_attr( (string) $redirect ) . '">' . esc_html__( 'Log in with a passkey', 'fw' ) . '</button> <span class="fw-mbr-passkey-msg" role="status"></span></p>';
	}

	/** @internal The same on wp-login.php. */
	public static function _wp_login_button() {
		self::enqueue();
		wp_print_scripts( 'fw-mbr-passkeys' );
		echo '<p class="fw-mbr-passkey-login" hidden style="margin:0 0 16px"><button type="button" class="button button-secondary" data-fw-mbr-passkey-login data-redirect="">' . esc_html__( 'Log in with a passkey', 'fw' ) . '</button> <span class="fw-mbr-passkey-msg" role="status"></span></p>';
	}

	/** @internal */
	public static function _account( $user ) {
		self::enqueue();
		$keys = self::all( $user->ID );
		?>
		<section class="fw-mbr-account__section" aria-labelledby="fw-mbr-acc-pk">
			<h2 id="fw-mbr-acc-pk"><?php esc_html_e( 'Passkeys', 'fw' ); ?></h2>
			<p class="fw-mbr-muted"><?php esc_html_e( 'Log in with your fingerprint, face or device PIN instead of a password. Add one on each device you use.', 'fw' ); ?></p>
			<?php if ( $keys ) : ?>
				<ul class="fw-mbr-account__payments">
					<?php foreach ( $keys as $k ) : ?>
						<li>
							<span><?php echo esc_html( $k['name'] ); ?></span>
							<span><?php echo esc_html( $k['last_used'] ? sprintf( __( 'last used %s', 'fw' ), date_i18n( get_option( 'date_format' ), strtotime( $k['last_used'] ) ) ) : sprintf( __( 'added %s', 'fw' ), date_i18n( get_option( 'date_format' ), strtotime( $k['created'] ) ) ) ); ?></span>
							<form method="post"><?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?><input type="hidden" name="fw_mbr_account_action" value="passkey_remove" /><input type="hidden" name="passkey" value="<?php echo esc_attr( $k['id'] ); ?>" /><button type="submit" class="fw-mbr-link-button"><?php esc_html_e( 'Remove', 'fw' ); ?></button></form>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="fw-mbr-passkey-add" hidden><button type="button" class="<?php echo esc_attr( FW_Membership_Settings::button_class( true ) ); ?>" data-fw-mbr-passkey-add><?php esc_html_e( 'Add a passkey on this device', 'fw' ); ?></button> <span class="fw-mbr-passkey-msg" role="status"></span></p>
		</section>
		<?php
	}

	/** @internal */
	public static function _handle( $code, $action, $user ) {
		if ( 'passkey_remove' !== $action ) {
			return $code;
		}
		$id   = sanitize_text_field( wp_unslash( $_POST['passkey'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$keys = array_filter( self::all( $user->ID ), function ( $k ) use ( $id ) {
			return $k['id'] !== $id;
		} );
		self::save_all( $user->ID, $keys );
		return 'passkey_removed';
	}

	/** @internal */
	public static function _admin_panel( $user_id ) {
		$keys = self::all( $user_id );
		?>
		<section class="fw-mbr-panel">
			<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'Passkeys', 'fw' ); ?></h2></header>
			<p class="fw-mbr-muted"><?php echo esc_html( $keys ? sprintf( _n( '%d passkey.', '%d passkeys.', count( $keys ), 'fw' ), count( $keys ) ) : __( 'None.', 'fw' ) ); ?></p>
		</section>
		<?php
	}
}
