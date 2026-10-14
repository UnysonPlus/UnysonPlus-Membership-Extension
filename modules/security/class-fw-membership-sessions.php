<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * A limit on devices signed in at once, per plan (plan setting max_sessions; 0 = no
 * limit). With several plans the most generous limit set applies; plans without one
 * do not count. Logging in on one device too many signs out the one used least
 * recently, and the log says so. Members see and end their sessions on the account page
 * ("Sign out everywhere else" is already there). Membership managers are never limited.
 */
class FW_Membership_Sessions {

	public static function init() {
		add_action( 'set_logged_in_cookie', array( __CLASS__, '_after_login' ), 20, 6 );
		add_action( 'unysonplus_membership_plan_option_fields', array( __CLASS__, '_plan_field' ) );
		add_filter( 'unysonplus_membership_plan_settings', array( __CLASS__, '_plan_save' ), 10, 2 );
		add_action( 'unysonplus_membership_account_sections', array( __CLASS__, '_account' ), 40 );
	}

	/** @return int The device limit for a user (0 = none). */
	public static function limit( $user_id ) {
		if ( user_can( (int) $user_id, FW_Membership_Service::capability() ) ) {
			return 0;
		}
		$limit = 0;
		foreach ( FW_Membership_Service::user_plans( (int) $user_id ) as $pid ) {
			$p = FW_Membership_Plans::get( $pid );
			$n = $p ? (int) ( $p['settings']['max_sessions'] ?? 0 ) : 0;
			$limit = max( $limit, $n );
		}
		return $limit;
	}

	/**
	 * Keep at most the limit: end the sessions used least recently.
	 *
	 * @param int    $user_id
	 * @param string $keep_token The session just made (never ended).
	 *
	 * @return int How many were ended.
	 */
	public static function enforce( $user_id, $keep_token = '' ) {
		$limit = self::limit( $user_id );
		if ( ! $limit ) {
			return 0;
		}
		$manager  = WP_Session_Tokens::get_instance( (int) $user_id );
		$sessions = $manager->get_all();
		if ( count( $sessions ) <= $limit ) {
			return 0;
		}
		// get_all() loses the verifiers, so read them from the stored array.
		$stored = (array) get_user_meta( (int) $user_id, 'session_tokens', true );
		$keep   = '' !== $keep_token ? hash( 'sha256', $keep_token ) : '';
		uasort( $stored, function ( $a, $b ) {
			return (int) ( $a['login'] ?? 0 ) <=> (int) ( $b['login'] ?? 0 );
		} );
		$ended = 0;
		foreach ( array_keys( $stored ) as $verifier ) {
			if ( count( $stored ) - $ended <= $limit ) {
				break;
			}
			if ( $verifier === $keep ) {
				continue;
			}
			unset( $stored[ $verifier ] );
			$ended++;
		}
		update_user_meta( (int) $user_id, 'session_tokens', $stored );
		if ( $ended && class_exists( 'FW_Membership_Log' ) ) {
			FW_Membership_Log::add( $user_id, 'session_ended', 0, sprintf( _n( '%d device', '%d devices', $ended, 'fw' ), $ended ) );
		}
		return $ended;
	}

	/** @internal Right after a login cookie is set. */
	public static function _after_login( $cookie, $expire, $expiration, $user_id, $scheme, $token ) {
		self::enforce( (int) $user_id, (string) $token );
	}

	/** @internal */
	public static function _plan_field( $plan ) {
		?>
		<p class="fw-mbr-field">
			<label for="fw-mbr-max-sessions"><?php esc_html_e( 'Devices signed in at once', 'fw' ); ?></label>
			<input type="number" min="0" id="fw-mbr-max-sessions" name="max_sessions" value="<?php echo (int) ( $plan['settings']['max_sessions'] ?? 0 ); ?>" />
			<span class="description"><?php esc_html_e( '0 = no limit. Logging in on one more signs out the one used least recently; this discourages sharing an account.', 'fw' ); ?></span>
		</p>
		<?php
	}

	/** @internal */
	public static function _plan_save( $settings, $posted ) {
		$settings['max_sessions'] = max( 0, (int) ( $posted['max_sessions'] ?? 0 ) );
		return $settings;
	}

	/** @internal The member's signed-in devices. */
	public static function _account( $user ) {
		$all = WP_Session_Tokens::get_instance( $user->ID )->get_all();
		if ( count( $all ) < 2 ) {
			return;
		}
		$limit = self::limit( $user->ID );
		?>
		<section class="fw-mbr-account__section" aria-labelledby="fw-mbr-acc-dev">
			<h2 id="fw-mbr-acc-dev"><?php esc_html_e( 'Signed in on', 'fw' ); ?></h2>
			<ul class="fw-mbr-account__payments">
				<?php foreach ( $all as $s ) : ?>
					<li><span><?php echo esc_html( self::describe( (string) ( $s['ua'] ?? '' ) ) ); ?></span><span><?php echo esc_html( sprintf( __( 'since %s', 'fw' ), date_i18n( get_option( 'date_format' ), (int) ( $s['login'] ?? 0 ) ) ) ); ?></span></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $limit ) : ?>
				<p class="fw-mbr-muted"><?php echo esc_html( sprintf( _n( 'Your plan allows %d device at a time.', 'Your plan allows %d devices at a time.', $limit, 'fw' ), $limit ) ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function describe( $ua ) {
		$old = $_SERVER['HTTP_USER_AGENT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['HTTP_USER_AGENT'] = $ua;
		$d = class_exists( 'FW_Membership_Log' ) ? FW_Membership_Log::agent() : '';
		$_SERVER['HTTP_USER_AGENT'] = $old;
		return '' !== $d ? $d : __( 'A browser', 'fw' );
	}
}
