<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Metered paywall and registration wall. Content behind a rule with "free reads" ticked
 * can be read a few times per period before the prompt appears:
 *
 *   visitors         `visitor_limit` items per period (0 = none: register first)
 *   logged-in, no plan  `account_limit` items per period (the registration wall: an
 *                    account is free and reads more)
 *
 * Each item counts once per period. Visitors are counted in a signed cookie (a soft
 * limit: clearing cookies resets it, as with every metered paywall), accounts in user
 * meta. Only the page being viewed is metered; lists, feeds and the REST API keep
 * showing the teaser. "View as" previews are never metered.
 */
class FW_Membership_Metered {

	const OPTION = 'fw_ext_membership_paywall';
	const COOKIE = 'fw_mbr_reads';
	const META   = '_fw_mbr_reads';

	/** @var array|null The free read given on this request: { used, limit }. */
	public static $read = null;

	/** @var bool The allowance ran out on this request. */
	public static $out = false;

	public static function init() {
		add_filter( 'unysonplus_membership_main_denied', array( __CLASS__, '_main_denied' ), 10, 2 );
		add_filter( 'unysonplus_membership_prompt_args', array( __CLASS__, '_prompt' ), 20, 3 );
		add_filter( 'the_content', array( __CLASS__, '_notice' ), 10000 );
		add_action( 'unysonplus_membership_rule_option_fields', array( __CLASS__, '_rule_field' ) );
		add_action( 'unysonplus_membership_settings_panels', array( __CLASS__, '_panel' ) );
		add_action( 'unysonplus_membership_save_settings', array( __CLASS__, '_save' ) );
	}

	/** @return array { visitor_limit, account_limit, period: month|week|day } */
	public static function settings() {
		return array_merge( array( 'visitor_limit' => 3, 'account_limit' => 5, 'period' => 'month' ), (array) get_option( self::OPTION, array() ) );
	}

	/** @return string The current period's key (e.g. 2026-10). */
	public static function period() {
		$p = self::settings()['period'];
		$t = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		return 'day' === $p ? gmdate( 'Y-m-d', $t ) : ( 'week' === $p ? gmdate( 'o-\WW', $t ) : gmdate( 'Y-m', $t ) );
	}

	/**
	 * The items read this period.
	 *
	 * @param int $user_id 0 for the visitor's cookie.
	 *
	 * @return int[]
	 */
	public static function reads( $user_id = 0 ) {
		$data = $user_id ? (array) get_user_meta( (int) $user_id, self::META, true ) : self::cookie();
		return ( $data['p'] ?? '' ) === self::period() ? array_map( 'intval', (array) ( $data['ids'] ?? array() ) ) : array();
	}

	private static function save_reads( $user_id, array $ids ) {
		$data = array( 'p' => self::period(), 'ids' => array_values( array_unique( array_map( 'intval', $ids ) ) ) );
		if ( $user_id ) {
			update_user_meta( (int) $user_id, self::META, $data );
			return;
		}
		$json = wp_json_encode( $data );
		$val  = base64_encode( $json ) . '.' . hash_hmac( 'sha256', $json, wp_salt( 'auth' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$_COOKIE[ self::COOKIE ] = $val;
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $val, array( 'expires' => time() + 40 * DAY_IN_SECONDS, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		}
	}

	/** @return array The visitor's signed reads cookie (empty when missing or tampered with). */
	private static function cookie() {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$dot = strrpos( $raw, '.' );
		if ( false === $dot ) {
			return array();
		}
		$json = (string) base64_decode( substr( $raw, 0, $dot ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( '' === $json || ! hash_equals( hash_hmac( 'sha256', $json, wp_salt( 'auth' ) ), substr( $raw, $dot + 1 ) ) ) {
			return array();
		}
		return (array) json_decode( $json, true );
	}

	/**
	 * Try to use a free read for an item.
	 *
	 * @param int   $post_id
	 * @param array $persona
	 *
	 * @return bool True when the person may read it now.
	 */
	public static function use_read( $post_id, array $persona ) {
		$s     = self::settings();
		$uid   = (int) $persona['user_id'];
		$limit = $uid ? (int) $s['account_limit'] : (int) $s['visitor_limit'];
		$reads = self::reads( $uid );
		if ( in_array( (int) $post_id, $reads, true ) ) {
			self::$read = array( 'used' => count( $reads ), 'limit' => $limit );
			return true; // already counted this period
		}
		if ( count( $reads ) >= $limit ) {
			self::$out = true;
			return false;
		}
		$reads[] = (int) $post_id;
		self::save_reads( $uid, $reads );
		self::$read = array( 'used' => count( $reads ), 'limit' => $limit );
		return true;
	}

	/** @internal */
	public static function _main_denied( $denied, $post ) {
		if ( ! $denied || empty( $denied['rule']['denied']['metered'] ) || ! empty( $denied['opens_at'] ) ) {
			return $denied;
		}
		$persona = FW_Membership_Access::persona();
		if ( '' !== (string) $persona['preview'] || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return $denied;
		}
		if ( $persona['plans'] ) {
			return $denied; // a member without the right plan: an upgrade, not a free read
		}
		return self::use_read( (int) $post->ID, $persona ) ? null : $denied;
	}

	/** @internal The allowance is used up: say so. */
	public static function _prompt( $args, $failing, $post ) {
		if ( ! self::$out || empty( $failing['rule']['denied']['metered'] ) ) {
			return $args;
		}
		$s  = self::settings();
		$in = is_user_logged_in();
		$p  = array( 'day' => __( 'today', 'fw' ), 'week' => __( 'this week', 'fw' ), 'month' => __( 'this month', 'fw' ) );
		$args['title'] = sprintf( __( 'You have read your free articles %s', 'fw' ), $p[ $s['period'] ] ?? $p['month'] );
		if ( ! $in && (int) $s['account_limit'] > (int) $s['visitor_limit'] ) {
			$args['message'] = sprintf( __( 'Create a free account to read %d a month, or join to read everything.', 'fw' ), (int) $s['account_limit'] );
		} elseif ( ! isset( $args['message'] ) ) {
			$args['message'] = __( 'Join to read everything, whenever you like.', 'fw' );
		}
		return $args;
	}

	/** @internal "2 free reads left" on a metered read. */
	public static function _notice( $content ) {
		if ( ! self::$read || ! is_singular() || ! in_the_loop() || (int) get_the_ID() !== (int) get_queried_object_id() ) {
			return $content;
		}
		$left = max( 0, self::$read['limit'] - self::$read['used'] );
		FW_Membership_Denied::enqueue();
		$msg  = 0 === $left ? __( 'This is your last free article for now.', 'fw' ) : sprintf( _n( 'You have %d free article left.', 'You have %d free articles left.', $left, 'fw' ), $left );
		$html = '<p class="fw-mbr-notice fw-mbr-metered" role="status">' . esc_html( $msg ) . ( ! is_user_logged_in() ? ' <a href="' . esc_url( FW_Membership_Settings::login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', 'fw' ) . '</a>' : '' ) . '</p>';
		self::$read = null; // once
		return $html . $content;
	}

	/** @internal */
	public static function _rule_field( $rule ) {
		?>
		<p class="fw-mbr-field">
			<label class="fw-mbr-check"><input type="checkbox" name="denied_metered" value="1" <?php checked( ! empty( $rule['denied']['metered'] ) ); ?> /> <?php esc_html_e( 'Free reads: people without access may read a few of these each period (Settings → Metered paywall)', 'fw' ); ?></label>
		</p>
		<?php
	}

	/** @internal */
	public static function _save( $r ) {
		update_option( self::OPTION, array(
			'visitor_limit' => max( 0, (int) ( $r['meter_visitor'] ?? 3 ) ),
			'account_limit' => max( 0, (int) ( $r['meter_account'] ?? 5 ) ),
			'period'        => in_array( $r['meter_period'] ?? '', array( 'day', 'week', 'month' ), true ) ? $r['meter_period'] : 'month',
		), false );
	}

	/** @internal */
	public static function _panel() {
		$s = self::settings();
		?>
		<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-meter">
			<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-meter"><?php esc_html_e( 'Metered paywall', 'fw' ); ?></h2></header>
			<p class="fw-mbr-muted"><?php esc_html_e( 'For access rules with "Free reads" ticked. Visitors are counted in their browser (clearing cookies resets it), accounts on the account.', 'fw' ); ?></p>
			<div class="fw-mbr-fields">
				<p class="fw-mbr-field">
					<label for="fw-mbr-meter-v"><?php esc_html_e( 'Free reads for visitors', 'fw' ); ?></label>
					<input type="number" min="0" id="fw-mbr-meter-v" name="meter_visitor" value="<?php echo (int) $s['visitor_limit']; ?>" />
				</p>
				<p class="fw-mbr-field">
					<label for="fw-mbr-meter-a"><?php esc_html_e( 'Free reads with a free account', 'fw' ); ?></label>
					<input type="number" min="0" id="fw-mbr-meter-a" name="meter_account" value="<?php echo (int) $s['account_limit']; ?>" />
					<span class="description"><?php esc_html_e( 'More than visitors get = a registration wall: people sign up to keep reading.', 'fw' ); ?></span>
				</p>
				<p class="fw-mbr-field">
					<label for="fw-mbr-meter-p"><?php esc_html_e( 'Counted per', 'fw' ); ?></label>
					<select id="fw-mbr-meter-p" name="meter_period">
						<option value="month" <?php selected( $s['period'], 'month' ); ?>><?php esc_html_e( 'Month', 'fw' ); ?></option>
						<option value="week" <?php selected( $s['period'], 'week' ); ?>><?php esc_html_e( 'Week', 'fw' ); ?></option>
						<option value="day" <?php selected( $s['period'], 'day' ); ?>><?php esc_html_e( 'Day', 'fw' ); ?></option>
					</select>
				</p>
			</div>
		</section>
		<?php
	}
}
