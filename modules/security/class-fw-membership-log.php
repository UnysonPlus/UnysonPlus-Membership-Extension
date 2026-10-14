<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Login and access log: logins (by password, link or passkey), failed logins, pages a
 * member was turned away from, plan changes and sessions signed out by the limit. IP
 * addresses are kept only as a keyed hash (enough to count how many places an account
 * is used from, not to say where). Entries older than 90 days are removed.
 *
 * Shown on each member's admin page, with a "used from N places in 30 days" line that
 * helps spot shared accounts. Table fw_mbr_log.
 */
class FW_Membership_Log {

	const VERSION = '1.0.0';
	const KEEP    = 90;

	public static function init() {
		FW_Membership_Installer::module_tables( 'security', self::VERSION, array( __CLASS__, 'schema' ), array( 'log' ) );
		add_action( 'wp_login', array( __CLASS__, '_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, '_failed' ) );
		add_action( 'unysonplus_membership_access_denied', array( __CLASS__, '_denied' ), 10, 2 );
		add_action( 'unysonplus_membership_plan_changed', array( __CLASS__, '_plan_changed' ), 30, 2 );
		add_action( 'unysonplus_membership_member_panels', array( __CLASS__, '_panel' ) );
		add_action( FW_Membership_Service::CRON_HOOK, array( __CLASS__, 'prune_daily' ) );
	}

	/** @internal */
	public static function schema( $collate ) {
		$t = FW_Membership_Installer::table( 'log' );
		return array( "CREATE TABLE {$t} (
	id bigint(20) unsigned NOT NULL auto_increment,
	user_id bigint(20) unsigned NOT NULL default 0,
	type varchar(30) NOT NULL default '',
	object_id bigint(20) unsigned NOT NULL default 0,
	detail varchar(190) NOT NULL default '',
	ip_hash varchar(64) NOT NULL default '',
	agent varchar(120) NOT NULL default '',
	created_at datetime default NULL,
	PRIMARY KEY  (id),
	KEY user_time (user_id,created_at),
	KEY type_time (type,created_at)
) {$collate};" );
	}

	/** @return string The current visitor's IP as a keyed hash. */
	public static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return '' !== $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) : '';
	}

	/** @return string A short description of the browser and the system it runs on. */
	public static function agent() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$b  = preg_match( '/Edg\//', $ua ) ? 'Edge' : ( preg_match( '/Firefox\//', $ua ) ? 'Firefox' : ( preg_match( '/Chrome\//', $ua ) ? 'Chrome' : ( preg_match( '/Safari\//', $ua ) ? 'Safari' : '' ) ) );
		$o  = preg_match( '/Android/', $ua ) ? 'Android' : ( preg_match( '/iPhone|iPad/', $ua ) ? 'iOS' : ( preg_match( '/Windows/', $ua ) ? 'Windows' : ( preg_match( '/Mac OS X/', $ua ) ? 'macOS' : ( preg_match( '/Linux/', $ua ) ? 'Linux' : '' ) ) ) );
		return trim( $b . ( '' !== $b && '' !== $o ? ' · ' : '' ) . $o );
	}

	/**
	 * Add an entry.
	 *
	 * @param int    $user_id
	 * @param string $type   login | login_link | login_passkey | login_failed | denied | plan_changed | session_ended
	 * @param int    $object_id
	 * @param string $detail
	 */
	public static function add( $user_id, $type, $object_id = 0, $detail = '' ) {
		global $wpdb;
		$wpdb->insert( FW_Membership_Installer::table( 'log' ), array(
			'user_id'    => (int) $user_id,
			'type'       => substr( sanitize_key( $type ), 0, 30 ),
			'object_id'  => (int) $object_id,
			'detail'     => substr( (string) $detail, 0, 190 ),
			'ip_hash'    => self::ip_hash(),
			'agent'      => substr( self::agent(), 0, 120 ),
			'created_at' => current_time( 'mysql' ),
		) );
	}

	/** @return array[] A member's latest entries. */
	public static function for_user( $user_id, $limit = 50 ) {
		global $wpdb;
		$t = FW_Membership_Installer::table( 'log' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d ORDER BY id DESC LIMIT %d", (int) $user_id, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @return int How many different places (IP hashes) logged in to an account in N days. */
	public static function places( $user_id, $days = 30 ) {
		global $wpdb;
		$t = FW_Membership_Installer::table( 'log' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ip_hash) FROM {$t} WHERE user_id = %d AND type LIKE 'login%%' AND type <> 'login_failed' AND created_at >= %s", (int) $user_id, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DateTime.CurrentTimeTimestamp
	}

	/** Remove entries older than the keep period. */
	public static function prune() {
		global $wpdb;
		$t = FW_Membership_Installer::table( 'log' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::KEEP * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DateTime.CurrentTimeTimestamp
	}

	/** @internal From the hourly job, at most once a day. */
	public static function prune_daily() {
		if ( get_transient( 'fw_mbr_log_pruned' ) ) {
			return;
		}
		set_transient( 'fw_mbr_log_pruned', 1, DAY_IN_SECONDS );
		self::prune();
	}

	/** @internal */
	public static function _login( $login, $user ) {
		$how = (string) apply_filters( 'unysonplus_membership_login_method', 'login' );
		self::add( $user->ID, $how );
	}

	/** @internal */
	public static function _failed( $login ) {
		$u = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		if ( $u ) {
			self::add( $u->ID, 'login_failed' );
		}
	}

	/** @internal */
	public static function _denied( $denied, $post_id ) {
		$uid = get_current_user_id();
		if ( $uid ) {
			self::add( $uid, 'denied', (int) $post_id, $post_id ? get_the_title( $post_id ) : (string) ( $denied['rule']['name'] ?? '' ) );
		}
	}

	/** @internal */
	public static function _plan_changed( $m, $ctx ) {
		$plan = FW_Membership_Plans::get( $m['plan_id'] );
		self::add( $m['user_id'], 'plan_changed', $m['id'], $plan ? $plan['name'] : '' );
	}

	/** @internal The log on a member's admin page. */
	public static function _panel( $user_id ) {
		$rows   = self::for_user( $user_id, 30 );
		$places = self::places( $user_id );
		$labels = array(
			'login'         => __( 'Logged in', 'fw' ),
			'login_link'    => __( 'Logged in with an email link', 'fw' ),
			'login_passkey' => __( 'Logged in with a passkey', 'fw' ),
			'login_failed'  => __( 'Wrong password', 'fw' ),
			'denied'        => __( 'Turned away from', 'fw' ),
			'plan_changed'  => __( 'Plan changed to', 'fw' ),
			'session_ended' => __( 'Signed out by the device limit', 'fw' ),
		);
		?>
		<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-log">
			<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-log"><?php esc_html_e( 'Log-ins and access', 'fw' ); ?></h2></header>
			<p class="<?php echo $places > 3 ? 'fw-mbr-mstatus fw-mbr-mstatus--past_due' : 'fw-mbr-muted'; ?>"><?php echo esc_html( sprintf( _n( 'Used from %d place in the last 30 days.', 'Used from %d places in the last 30 days.', $places, 'fw' ), $places ) ); ?><?php echo $places > 3 ? ' ' . esc_html__( 'That is a lot: the account may be shared.', 'fw' ) : ''; ?></p>
			<?php if ( ! $rows ) : ?>
				<p class="fw-mbr-muted"><?php esc_html_e( 'Nothing yet.', 'fw' ); ?></p>
			<?php else : ?>
				<ol class="fw-mbr-history">
					<?php foreach ( $rows as $r ) : ?>
						<li>
							<span class="fw-mbr-history__what"><strong><?php echo esc_html( $labels[ $r['type'] ] ?? $r['type'] ); ?></strong> <?php echo esc_html( $r['detail'] ); ?></span>
							<span class="fw-mbr-history__when fw-mbr-muted"><?php echo FW_Membership_App::time_ago( $r['created_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $r['agent'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
		<?php
	}
}
