<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Group and team memberships. A plan with "Team seats" (plan settings team_seats) gives
 * whoever holds it a team: that many people besides themselves, invited by email, by a
 * join link, or automatically by email domain.
 *
 *   Each team member holds the same plan (source group_seat, source_ref = group id),
 *   which follows the owner's: a renewal extends it, a pause pauses it, a cancellation
 *   cancels it to the same end date, and an end ends it.
 *   Invites waiting for an answer hold a seat; the owner can resend or withdraw them,
 *   and remove members (their seat frees at once).
 *
 * Tables fw_mbr_groups and fw_mbr_group_members.
 */
class FW_Membership_Groups {

	const VERSION = '1.0.0';
	const NONCE   = 'fw_mbr_team';

	public static function init() {
		FW_Membership_Installer::module_tables( 'groups', self::VERSION, array( __CLASS__, 'schema' ), array( 'groups', 'group_members' ) );
		add_action( 'unysonplus_membership_changed', array( __CLASS__, '_changed' ), 15, 3 );
		add_action( 'unysonplus_membership_plan_option_fields', array( __CLASS__, '_plan_field' ) );
		add_filter( 'unysonplus_membership_plan_settings', array( __CLASS__, '_plan_save' ), 10, 2 );
		add_action( 'unysonplus_membership_account_sections', array( __CLASS__, '_team_section' ), 3 );
		add_filter( 'unysonplus_membership_account_action', array( __CLASS__, '_handle' ), 10, 3 );
		add_filter( 'unysonplus_membership_account_notices', array( __CLASS__, '_notices' ) );
		add_filter( 'unysonplus_membership_account_cancel_html', array( __CLASS__, '_cancel_html' ), 20, 2 );
		add_filter( 'unysonplus_membership_account_takeover', array( __CLASS__, '_join_page' ), 5, 2 );
		add_filter( 'unysonplus_membership_account_guest_takeover', array( __CLASS__, '_join_page_guest' ) );
		add_action( 'template_redirect', array( __CLASS__, '_join_post' ), 3 );
		add_action( 'wp_login', array( __CLASS__, '_domain_join' ), 20, 2 );
		add_filter( 'unysonplus_membership_email_catalogue', array( __CLASS__, '_emails' ) );
	}

	/** @internal */
	public static function schema( $collate ) {
		$g = FW_Membership_Installer::table( 'groups' );
		$m = FW_Membership_Installer::table( 'group_members' );
		return array(
			"CREATE TABLE {$g} (
	id bigint(20) unsigned NOT NULL auto_increment,
	owner_id bigint(20) unsigned NOT NULL default 0,
	membership_id bigint(20) unsigned NOT NULL default 0,
	plan_id bigint(20) unsigned NOT NULL default 0,
	name varchar(190) NOT NULL default '',
	seats int(11) unsigned NOT NULL default 0,
	join_code varchar(40) NOT NULL default '',
	domain varchar(190) NOT NULL default '',
	status varchar(20) NOT NULL default 'active',
	created_at datetime default NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY membership (membership_id),
	KEY owner (owner_id),
	KEY join_code (join_code)
) {$collate};",
			"CREATE TABLE {$m} (
	id bigint(20) unsigned NOT NULL auto_increment,
	group_id bigint(20) unsigned NOT NULL default 0,
	user_id bigint(20) unsigned NOT NULL default 0,
	email varchar(190) NOT NULL default '',
	status varchar(20) NOT NULL default 'invited',
	token varchar(40) NOT NULL default '',
	membership_id bigint(20) unsigned NOT NULL default 0,
	invited_at datetime default NULL,
	joined_at datetime default NULL,
	PRIMARY KEY  (id),
	KEY group_status (group_id,status),
	KEY user_id (user_id),
	KEY token (token)
) {$collate};",
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Repository
	 * ---------------------------------------------------------------------- */

	private static function t( $name ) {
		return FW_Membership_Installer::table( $name );
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'groups' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::shape( $row ) : null;
	}

	public static function for_membership( $membership_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'groups' ) . ' WHERE membership_id = %d', (int) $membership_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::shape( $row ) : null;
	}

	public static function owned_by( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'groups' ) . " WHERE owner_id = %d AND status = 'active' ORDER BY id", (int) $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	public static function all( $limit = 200 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'groups' ) . ' ORDER BY id DESC LIMIT %d', (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	public static function by_code( $code ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'groups' ) . " WHERE join_code = %s AND status = 'active'", (string) $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::shape( $row ) : null;
	}

	public static function members( $group_id, $statuses = array( 'invited', 'active' ) ) {
		global $wpdb;
		$in   = "'" . implode( "','", array_map( 'esc_sql', (array) $statuses ) ) . "'";
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'group_members' ) . " WHERE group_id = %d AND status IN ({$in}) ORDER BY id", (int) $group_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return (array) $rows;
	}

	public static function member_by_token( $token ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'group_members' ) . " WHERE token = %s AND status = 'invited'", (string) $token ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @return int Seats taken (members + waiting invites). */
	public static function used( $group_id ) {
		return count( self::members( $group_id ) );
	}

	public static function update( $id, array $row ) {
		global $wpdb;
		$wpdb->update( self::t( 'groups' ), $row, array( 'id' => (int) $id ) );
	}

	private static function shape( array $r ) {
		foreach ( array( 'id', 'owner_id', 'membership_id', 'plan_id', 'seats' ) as $k ) {
			$r[ $k ] = (int) $r[ $k ];
		}
		return $r;
	}

	/** @return int A plan's team seats (0 = not a team plan). */
	public static function seats_for_plan( $plan_id ) {
		$p = FW_Membership_Plans::get( $plan_id );
		return $p ? max( 0, (int) ( $p['settings']['team_seats'] ?? 0 ) ) : 0;
	}

	/* ---------------------------------------------------------------------- *
	 * Seats follow the owner
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _changed( $event, $m, $ctx ) {
		if ( 'group_seat' === $m['source'] ) {
			return;
		}
		$g = self::for_membership( $m['id'] );
		if ( ! $g && in_array( $event, array( 'joined', 'trial_started', 'plan_changed' ), true ) && self::seats_for_plan( $m['plan_id'] ) ) {
			self::create( $m );
			return;
		}
		if ( ! $g ) {
			return;
		}
		if ( 'plan_changed' === $event ) {
			self::update( $g['id'], array( 'plan_id' => $m['plan_id'], 'seats' => self::seats_for_plan( $m['plan_id'] ) ) );
		}
		self::sync( $g['id'] );
	}

	/**
	 * Make the team for a membership.
	 *
	 * @param array $m
	 *
	 * @return int
	 */
	public static function create( array $m ) {
		global $wpdb;
		$u = get_userdata( $m['user_id'] );
		$wpdb->insert( self::t( 'groups' ), array(
			'owner_id'      => $m['user_id'],
			'membership_id' => $m['id'],
			'plan_id'       => $m['plan_id'],
			'name'          => $u ? sprintf( __( '%s’s team', 'fw' ), $u->display_name ) : __( 'Team', 'fw' ),
			'seats'         => self::seats_for_plan( $m['plan_id'] ),
			'join_code'     => strtolower( wp_generate_password( 16, false, false ) ),
			'status'        => 'active',
			'created_at'    => current_time( 'mysql' ),
		) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Put every seat in step with the owner's membership.
	 *
	 * @param int $group_id
	 */
	public static function sync( $group_id ) {
		$g     = self::get( $group_id );
		$owner = $g ? FW_Membership_Memberships::get( $g['membership_id'] ) : null;
		if ( ! $g || ! $owner ) {
			return;
		}
		$ended = in_array( $owner['status'], array( 'expired', 'refunded' ), true );
		if ( $ended ) {
			self::update( $g['id'], array( 'status' => 'ended' ) );
		}
		foreach ( self::members( $g['id'], array( 'active' ) ) as $row ) {
			$seat = FW_Membership_Memberships::get( (int) $row['membership_id'] );
			if ( ! $seat ) {
				continue;
			}
			if ( $ended ) {
				if ( 'expired' !== $seat['status'] ) {
					FW_Membership_Service::expire( $seat['id'], __( 'The team membership ended.', 'fw' ) );
				}
				continue;
			}
			$want = array( 'plan_id' => $owner['plan_id'], 'ends_at' => $owner['ends_at'] );
			if ( 'paused' === $owner['status'] && 'paused' !== $seat['status'] ) {
				FW_Membership_Service::pause( $seat['id'], (string) ( $owner['meta']['paused_until'] ?? '' ), __( 'The team membership is paused.', 'fw' ) );
			} elseif ( 'paused' !== $owner['status'] && 'paused' === $seat['status'] ) {
				FW_Membership_Service::resume( $seat['id'] );
			} elseif ( 'cancelled' === $owner['status'] && 'cancelled' !== $seat['status'] ) {
				FW_Membership_Memberships::update( $seat['id'], $want );
				FW_Membership_Service::cancel( $seat['id'] );
				continue;
			} elseif ( in_array( $owner['status'], array( 'active', 'trialing', 'past_due' ), true ) && 'cancelled' === $seat['status'] ) {
				FW_Membership_Service::reactivate( $seat['id'] );
			}
			FW_Membership_Memberships::update( $seat['id'], $want );
		}
		FW_Membership_Service::flush_cache();
	}

	/**
	 * Give a person a seat.
	 *
	 * @param array $g
	 * @param int   $user_id
	 * @param int   $row_id An invite row to fill (0 = a new row).
	 *
	 * @return int|WP_Error The seat membership id.
	 */
	public static function seat( array $g, $user_id, $row_id = 0 ) {
		global $wpdb;
		$owner = FW_Membership_Memberships::get( $g['membership_id'] );
		if ( 'active' !== $g['status'] || ! $owner || ! in_array( $owner['status'], FW_Membership_Memberships::ACCESS_STATUSES, true ) ) {
			return new WP_Error( 'fw_mbr_team_ended', __( 'This team is no longer active.', 'fw' ) );
		}
		if ( (int) $user_id === (int) $g['owner_id'] ) {
			return new WP_Error( 'fw_mbr_team_owner', __( 'You own this team already.', 'fw' ) );
		}
		foreach ( self::members( $g['id'], array( 'active' ) ) as $row ) {
			if ( (int) $row['user_id'] === (int) $user_id ) {
				return new WP_Error( 'fw_mbr_team_in', __( 'You are already in this team.', 'fw' ) );
			}
		}
		if ( ! $row_id && self::used( $g['id'] ) >= $g['seats'] ) {
			return new WP_Error( 'fw_mbr_team_full', __( 'This team has no free seats. Ask its owner for one.', 'fw' ) );
		}
		$held = FW_Membership_Memberships::query( array( 'user_id' => (int) $user_id, 'plan_id' => $owner['plan_id'], 'status' => 'access', 'per_page' => 1 ) );
		if ( $held ) {
			return new WP_Error( 'fw_mbr_team_has', __( 'You already have this plan yourself.', 'fw' ) );
		}
		$mid = FW_Membership_Service::grant( (int) $user_id, $owner['plan_id'], array( 'source' => 'group_seat', 'source_ref' => (string) $g['id'], 'notify' => true, 'ends' => $owner['ends_at'] ? $owner['ends_at'] : 'never' ) );
		if ( is_wp_error( $mid ) ) {
			return $mid;
		}
		$u   = get_userdata( (int) $user_id );
		$row = array( 'user_id' => (int) $user_id, 'email' => $u ? $u->user_email : '', 'status' => 'active', 'membership_id' => (int) $mid, 'joined_at' => current_time( 'mysql' ), 'token' => '' );
		if ( $row_id ) {
			$wpdb->update( self::t( 'group_members' ), $row, array( 'id' => (int) $row_id ) );
		} else {
			$wpdb->insert( self::t( 'group_members' ), array_merge( $row, array( 'group_id' => $g['id'], 'invited_at' => current_time( 'mysql' ) ) ) );
		}
		return (int) $mid;
	}

	/**
	 * Free a seat (the owner removes someone, or they leave).
	 *
	 * @param array $row group_members row
	 */
	public static function unseat( array $row ) {
		global $wpdb;
		if ( $row['membership_id'] ) {
			FW_Membership_Service::expire( (int) $row['membership_id'], __( 'Removed from the team.', 'fw' ) );
		}
		$wpdb->update( self::t( 'group_members' ), array( 'status' => 'removed' ), array( 'id' => (int) $row['id'] ) );
	}

	/**
	 * Invite people by email.
	 *
	 * @param array    $g
	 * @param string[] $emails
	 *
	 * @return array { invited: int, skipped: string[] }
	 */
	public static function invite( array $g, array $emails ) {
		global $wpdb;
		$out  = array( 'invited' => 0, 'skipped' => array() );
		$have = array_map( 'strtolower', wp_list_pluck( self::members( $g['id'] ), 'email' ) );
		foreach ( $emails as $raw ) {
			$raw   = trim( (string) $raw );
			$email = sanitize_email( $raw );
			if ( ! is_email( $email ) || in_array( strtolower( $email ), $have, true ) ) {
				if ( '' !== $raw ) {
					$out['skipped'][] = sanitize_text_field( $raw ); // reported back: not valid, or already invited
				}
				continue;
			}
			if ( self::used( $g['id'] ) >= $g['seats'] ) {
				$out['skipped'][] = $email;
				continue;
			}
			$token = strtolower( wp_generate_password( 24, false, false ) );
			$wpdb->insert( self::t( 'group_members' ), array( 'group_id' => $g['id'], 'email' => $email, 'status' => 'invited', 'token' => $token, 'invited_at' => current_time( 'mysql' ) ) );
			self::send_invite( $g, $email, $token );
			$have[] = strtolower( $email );
			$out['invited']++;
		}
		return $out;
	}

	public static function send_invite( array $g, $email, $token ) {
		$owner = get_userdata( $g['owner_id'] );
		$plan  = FW_Membership_Plans::get( $g['plan_id'] );
		FW_Membership_Emails::send( 'team_invite', $g['owner_id'], array(
			'owner_name' => $owner ? $owner->display_name : '',
			'team_name'  => $g['name'],
			'plan'       => $plan ? $plan['name'] : '',
			'join_url'   => add_query_arg( 'fw_mbr_team', $token, FW_Membership_Settings::account_url() ),
		), false, $email );
	}

	/** @return string The team's join link (anyone with it may take a free seat). */
	public static function join_url( array $g ) {
		return add_query_arg( 'fw_mbr_team_code', $g['join_code'], FW_Membership_Settings::account_url() );
	}

	/* ---------------------------------------------------------------------- *
	 * Joining
	 * ---------------------------------------------------------------------- */

	/**
	 * What the current request wants to join: { group, row (invite) }.
	 *
	 * @return array|null
	 */
	private static function requested() {
		// phpcs:disable WordPress.Security.NonceVerification -- a token in a link.
		if ( isset( $_REQUEST['fw_mbr_team'] ) ) {
			$row = self::member_by_token( sanitize_key( wp_unslash( $_REQUEST['fw_mbr_team'] ) ) );
			$g   = $row ? self::get( $row['group_id'] ) : null;
			return $g ? array( 'group' => $g, 'row' => $row ) : array( 'group' => null, 'row' => null );
		}
		if ( isset( $_REQUEST['fw_mbr_team_code'] ) ) {
			$g = self::by_code( sanitize_key( wp_unslash( $_REQUEST['fw_mbr_team_code'] ) ) );
			return array( 'group' => $g, 'row' => null );
		}
		// phpcs:enable
		return null;
	}

	private static function join_html( $req, $logged_in ) {
		$g = $req['group'];
		ob_start();
		echo '<section class="fw-mbr-account__section fw-mbr-team-join">';
		if ( ! $g || 'active' !== $g['status'] ) {
			echo '<p class="fw-mbr-notice">' . esc_html__( 'This invitation is no longer valid. Ask the team owner for a new one.', 'fw' ) . '</p></section>';
			return (string) ob_get_clean();
		}
		$owner = get_userdata( $g['owner_id'] );
		$plan  = FW_Membership_Plans::get( $g['plan_id'] );
		$err   = get_transient( 'fw_mbr_team_err_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		?>
		<h2><?php echo esc_html( sprintf( __( 'Join %s', 'fw' ), $g['name'] ) ); ?></h2>
		<p><?php echo esc_html( sprintf( __( '%1$s invites you to their %2$s team membership.', 'fw' ), $owner ? $owner->display_name : '', $plan ? $plan['name'] : '' ) ); ?></p>
		<?php if ( $err ) : ?>
			<p class="fw-mbr-notice fw-mbr-notice--error" role="alert"><?php echo esc_html( $err ); ?></p>
		<?php endif; ?>
		<form method="post" class="fw-mbr-form">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="fw_mbr_team_join" value="1" />
			<?php if ( $req['row'] ) : ?>
				<input type="hidden" name="fw_mbr_team" value="<?php echo esc_attr( $req['row']['token'] ); ?>" />
			<?php else : ?>
				<input type="hidden" name="fw_mbr_team_code" value="<?php echo esc_attr( $g['join_code'] ); ?>" />
			<?php endif; ?>
			<?php if ( ! $logged_in ) : ?>
				<p class="fw-mbr-form__row"><?php esc_html_e( 'Already have an account?', 'fw' ); ?> <a href="<?php echo esc_url( FW_Membership_Settings::login_url( add_query_arg( array() ) ) ); ?>"><?php esc_html_e( 'Log in first', 'fw' ); ?></a></p>
				<p class="fw-mbr-form__row"><label for="fw-mbr-tj-first"><?php esc_html_e( 'First name', 'fw' ); ?></label><input type="text" id="fw-mbr-tj-first" name="first_name" /></p>
				<p class="fw-mbr-form__row"><label for="fw-mbr-tj-email"><?php esc_html_e( 'Email', 'fw' ); ?></label><input type="email" id="fw-mbr-tj-email" name="email" value="<?php echo esc_attr( $req['row'] ? $req['row']['email'] : '' ); ?>" required /></p>
				<p class="fw-mbr-form__row"><label for="fw-mbr-tj-pass"><?php esc_html_e( 'Choose a password', 'fw' ); ?></label><input type="password" id="fw-mbr-tj-pass" name="password" minlength="8" autocomplete="new-password" required /></p>
			<?php endif; ?>
			<p class="fw-mbr-form__actions"><button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class() ); ?>"><?php esc_html_e( 'Join the team', 'fw' ); ?></button></p>
		</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** @internal */
	public static function _join_page( $html, $user ) {
		$req = self::requested();
		return null === $req || '' !== $html ? $html : self::join_html( $req, true );
	}

	/** @internal */
	public static function _join_page_guest( $html ) {
		$req = self::requested();
		return null === $req || '' !== $html ? $html : self::join_html( $req, false );
	}

	/** @internal */
	public static function _join_post() {
		if ( empty( $_POST['fw_mbr_team_join'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( self::NONCE );
		$req  = self::requested();
		$fail = function ( $msg ) {
			set_transient( 'fw_mbr_team_err_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ), $msg, MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : FW_Membership_Settings::account_url() );
			exit;
		};
		if ( ! $req || ! $req['group'] ) {
			$fail( __( 'This invitation is no longer valid.', 'fw' ) );
		}
		$uid = get_current_user_id();
		if ( ! $uid ) {
			$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$pass  = (string) wp_unslash( $_POST['password'] ?? '' );
			if ( ! is_email( $email ) || strlen( $pass ) < 8 ) {
				$fail( __( 'Enter your email and a password of at least 8 characters.', 'fw' ) );
			}
			if ( email_exists( $email ) ) {
				$fail( __( 'An account with this email already exists. Please log in first.', 'fw' ) );
			}
			$uid = wp_insert_user( array( 'user_login' => self::username( $email ), 'user_email' => $email, 'user_pass' => $pass, 'first_name' => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ), 'role' => get_option( 'default_role', 'subscriber' ) ) );
			if ( is_wp_error( $uid ) ) {
				$fail( $uid->get_error_message() );
			}
			wp_set_current_user( $uid );
			wp_set_auth_cookie( $uid, true );
		}
		$r = self::seat( $req['group'], $uid, $req['row'] ? (int) $req['row']['id'] : 0 );
		if ( is_wp_error( $r ) ) {
			$fail( $r->get_error_message() );
		}
		wp_safe_redirect( add_query_arg( 'fw_mbr_notice', 'team_joined', FW_Membership_Settings::account_url() ) );
		exit;
	}

	private static function username( $email ) {
		$base = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base = strlen( $base ) >= 3 ? substr( $base, 0, 50 ) : 'member';
		$name = $base;
		$n    = 1;
		while ( username_exists( $name ) ) {
			$name = $base . ( ++$n );
		}
		return $name;
	}

	/** @internal Logging in with an email on a team's domain takes a free seat. */
	public static function _domain_join( $login, $user ) {
		global $wpdb;
		$domain = strtolower( (string) substr( strrchr( (string) $user->user_email, '@' ), 1 ) );
		if ( '' === $domain ) {
			return;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'groups' ) . " WHERE domain = %s AND status = 'active'", $domain ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( (array) $rows as $row ) {
			$g = self::shape( $row );
			if ( self::used( $g['id'] ) < $g['seats'] ) {
				$r = self::seat( $g, $user->ID );
				if ( ! is_wp_error( $r ) ) {
					return;
				}
			}
		}
	}

	/* ---------------------------------------------------------------------- *
	 * The owner's team page (account) and member actions
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _team_section( $user ) {
		foreach ( self::owned_by( $user->ID ) as $g ) {
			$rows = self::members( $g['id'] );
			?>
			<section class="fw-mbr-account__section fw-mbr-team" aria-labelledby="fw-mbr-team-<?php echo (int) $g['id']; ?>">
				<h2 id="fw-mbr-team-<?php echo (int) $g['id']; ?>"><?php echo esc_html( sprintf( __( 'Your team: %1$d of %2$d seats used', 'fw' ), count( $rows ), $g['seats'] ) ); ?></h2>
				<?php if ( $rows ) : ?>
					<ul class="fw-mbr-account__payments">
						<?php foreach ( $rows as $row ) : ?>
							<?php $u = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null; ?>
							<li>
								<span><?php echo esc_html( $u ? $u->display_name : $row['email'] ); ?></span>
								<span class="fw-mbr-account__status"><?php echo esc_html( 'active' === $row['status'] ? __( 'Member', 'fw' ) : __( 'Invited', 'fw' ) ); ?></span>
								<form method="post">
									<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
									<input type="hidden" name="fw_mbr_account_action" value="team_remove" />
									<input type="hidden" name="row" value="<?php echo (int) $row['id']; ?>" />
									<button type="submit" class="fw-mbr-link-button"><?php echo esc_html( 'active' === $row['status'] ? __( 'Remove', 'fw' ) : __( 'Withdraw', 'fw' ) ); ?></button>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( count( $rows ) < $g['seats'] ) : ?>
					<form method="post" class="fw-mbr-form">
						<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
						<input type="hidden" name="fw_mbr_account_action" value="team_invite" />
						<input type="hidden" name="group" value="<?php echo (int) $g['id']; ?>" />
						<p class="fw-mbr-form__row">
							<label for="fw-mbr-team-emails-<?php echo (int) $g['id']; ?>"><?php esc_html_e( 'Invite by email (separate several with commas)', 'fw' ); ?></label>
							<input type="text" id="fw-mbr-team-emails-<?php echo (int) $g['id']; ?>" name="emails" placeholder="anna@example.com, ben@example.com" />
						</p>
						<p class="fw-mbr-form__actions"><button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class( true ) ); ?>"><?php esc_html_e( 'Send invitations', 'fw' ); ?></button></p>
					</form>
					<p class="fw-mbr-form__row"><?php esc_html_e( 'Or share this link (anyone who has it can take a free seat):', 'fw' ); ?><br /><input type="text" readonly value="<?php echo esc_attr( self::join_url( $g ) ); ?>" onclick="this.select()" class="fw-mbr-team__link" /></p>
				<?php endif; ?>
				<form method="post" class="fw-mbr-form fw-mbr-form--inline">
					<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
					<input type="hidden" name="fw_mbr_account_action" value="team_domain" />
					<input type="hidden" name="group" value="<?php echo (int) $g['id']; ?>" />
					<p class="fw-mbr-form__row">
						<label for="fw-mbr-team-domain-<?php echo (int) $g['id']; ?>"><?php esc_html_e( 'Let everyone with an email at this domain join when they log in', 'fw' ); ?></label>
						<input type="text" id="fw-mbr-team-domain-<?php echo (int) $g['id']; ?>" name="domain" value="<?php echo esc_attr( $g['domain'] ); ?>" placeholder="example.com" />
						<button type="submit" class="fw-mbr-link-button"><?php esc_html_e( 'Save', 'fw' ); ?></button>
					</p>
				</form>
			</section>
			<?php
		}
	}

	/** @internal */
	public static function _handle( $code, $action, $user ) {
		if ( 0 !== strpos( $action, 'team_' ) && 'leave_team' !== $action ) {
			return $code;
		}
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification -- checked by FW_Membership_Accounts.
		switch ( $action ) {
			case 'team_invite':
				$g = self::get( (int) ( $_POST['group'] ?? 0 ) );
				if ( ! $g || $g['owner_id'] !== (int) $user->ID ) {
					return 'failed';
				}
				$r = self::invite( $g, preg_split( '/[\s,;]+/', (string) wp_unslash( $_POST['emails'] ?? '' ) ) );
				return $r['invited'] ? 'team_invited' : 'team_none';

			case 'team_remove':
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'group_members' ) . ' WHERE id = %d', (int) ( $_POST['row'] ?? 0 ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
				$g   = $row ? self::get( $row['group_id'] ) : null;
				if ( ! $g || $g['owner_id'] !== (int) $user->ID ) {
					return 'failed';
				}
				self::unseat( $row );
				return 'team_removed';

			case 'team_domain':
				$g = self::get( (int) ( $_POST['group'] ?? 0 ) );
				if ( ! $g || $g['owner_id'] !== (int) $user->ID ) {
					return 'failed';
				}
				$d = strtolower( preg_replace( '/[^a-z0-9.-]/i', '', (string) wp_unslash( $_POST['domain'] ?? '' ) ) );
				if ( in_array( $d, array( 'gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'icloud.com' ), true ) ) {
					return 'team_domain_public';
				}
				self::update( $g['id'], array( 'domain' => $d ) );
				return 'team_saved';

			case 'leave_team':
				$m   = FW_Membership_Memberships::get( (int) ( $_POST['membership'] ?? 0 ) );
				$row = $m && 'group_seat' === $m['source'] && (int) $m['user_id'] === (int) $user->ID ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'group_members' ) . " WHERE membership_id = %d AND status = 'active'", $m['id'] ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL
				if ( ! $row ) {
					return 'failed';
				}
				self::unseat( $row );
				return 'team_left';
		}
		// phpcs:enable
		return $code;
	}

	/** @internal A team member leaves rather than cancels. */
	public static function _cancel_html( $html, $m ) {
		if ( 'group_seat' !== $m['source'] ) {
			return $html;
		}
		ob_start();
		?>
		<form method="post" class="fw-mbr-account__cancel" onsubmit="return confirm('<?php echo esc_js( __( 'Leave this team? Your access ends now and your seat goes back to the team.', 'fw' ) ); ?>');">
			<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
			<input type="hidden" name="fw_mbr_account_action" value="leave_team" />
			<input type="hidden" name="membership" value="<?php echo (int) $m['id']; ?>" />
			<button type="submit" class="fw-mbr-link-button"><?php esc_html_e( 'Leave the team', 'fw' ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/** @internal */
	public static function _notices( $n ) {
		$n['team_joined']        = array( 'success', __( 'Welcome to the team! Your membership has started.', 'fw' ) );
		$n['team_invited']       = array( 'success', __( 'Invitations sent.', 'fw' ) );
		$n['team_none']          = array( 'error', __( 'Nobody was invited: check the addresses, and that there are free seats.', 'fw' ) );
		$n['team_removed']       = array( 'success', __( 'Done. The seat is free again.', 'fw' ) );
		$n['team_saved']         = array( 'success', __( 'Saved.', 'fw' ) );
		$n['team_domain_public'] = array( 'error', __( 'Use your organisation’s own domain, not a public email service.', 'fw' ) );
		$n['team_left']          = array( 'success', __( 'You have left the team.', 'fw' ) );
		return $n;
	}

	/** @internal */
	public static function _emails( $c ) {
		$c['team_invite'] = array(
			'label'   => __( 'Team invitation', 'fw' ),
			'to'      => 'member',
			'when'    => __( 'When a team owner invites someone by email.', 'fw' ),
			'subject' => __( '{owner_name} invites you to {team_name}', 'fw' ),
			'body'    => __( "Hello,\n\n{owner_name} has invited you to join their {plan} team membership at {site_name}. Accept here:\n\n{join_url}", 'fw' ),
		);
		return $c;
	}

	/** @internal */
	public static function _plan_field( $plan ) {
		?>
		<p class="fw-mbr-field">
			<label for="fw-mbr-team-seats"><?php esc_html_e( 'Team seats', 'fw' ); ?></label>
			<input type="number" min="0" id="fw-mbr-team-seats" name="team_seats" value="<?php echo (int) ( $plan['settings']['team_seats'] ?? 0 ); ?>" aria-describedby="fw-mbr-team-seats-d" />
			<span class="description" id="fw-mbr-team-seats-d"><?php esc_html_e( 'A team plan: how many people the buyer can bring in besides themselves (0 = not a team plan). They manage the team on their account page.', 'fw' ); ?></span>
		</p>
		<?php
	}

	/** @internal */
	public static function _plan_save( $settings, $posted ) {
		$settings['team_seats'] = max( 0, (int) ( $posted['team_seats'] ?? 0 ) );
		return $settings;
	}
}
