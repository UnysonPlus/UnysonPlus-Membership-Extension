<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Drip content: an access rule can open N days after the person joined, or on a date.
 * Until then, people who otherwise pass the rule see "This opens for you on …" instead
 * of the content, their account page lists what is coming up, and a daily job emails
 * them when something opens. A sequence is several rules on different items with
 * rising day counts (day 0, 7, 14…).
 *
 * "Joined" is the start of the earliest membership with access that satisfies the rule
 * (any plan for a rule without plans); for a rule passed through a role, logging in or
 * the CRM, it is the date the account was made.
 */
class FW_Membership_Drip {

	const CRON = 'fw_mbr_drip_daily';
	const LAST = 'fw_ext_membership_drip_last_run';

	public static function init() {
		add_filter( 'unysonplus_membership_rule_opens_at', array( __CLASS__, 'opens_at' ), 10, 3 );
		add_filter( 'unysonplus_membership_prompt_args', array( __CLASS__, '_prompt' ), 10, 3 );
		add_action( 'unysonplus_membership_rule_option_fields', array( __CLASS__, '_fields' ) );
		add_action( 'unysonplus_membership_account_sections', array( __CLASS__, '_account' ), 5 );
		add_filter( 'unysonplus_membership_email_catalogue', array( __CLASS__, '_email' ) );
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( strtotime( 'tomorrow 07:00' ), 'daily', self::CRON );
		}
	}

	/**
	 * When a rule opens for a person (local unix time; 0 = open now).
	 *
	 * @param int   $opens
	 * @param array $rule
	 * @param array $persona
	 *
	 * @return int
	 */
	public static function opens_at( $opens, $rule, $persona ) {
		$at = 0;
		if ( ! empty( $rule['drip_date'] ) ) {
			$at = (int) strtotime( $rule['drip_date'] );
		}
		$days = (int) ( $rule['drip_days'] ?? 0 );
		if ( $days > 0 ) {
			$base = self::joined( $rule, $persona );
			$at   = max( $at, $base + $days * DAY_IN_SECONDS );
		}
		return max( (int) $opens, $at );
	}

	/**
	 * When the person "joined" for a rule (local unix time).
	 *
	 * @param array $rule
	 * @param array $persona
	 *
	 * @return int
	 */
	public static function joined( array $rule, array $persona ) {
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		if ( empty( $persona['user_id'] ) ) {
			return $now; // a "View as" preview: as if they joined today
		}
		$earliest = 0;
		foreach ( FW_Membership_Memberships::query( array( 'user_id' => (int) $persona['user_id'], 'status' => 'access', 'per_page' => 50 ) ) as $m ) {
			if ( ! $m['started_at'] ) {
				continue;
			}
			$lineage = FW_Membership_Plans::lineage( $m['plan_id'] );
			if ( $rule['who']['plans'] && ! array_intersect( $rule['who']['plans'], $lineage ) ) {
				continue;
			}
			$t = (int) strtotime( $m['started_at'] );
			if ( ! $earliest || $t < $earliest ) {
				$earliest = $t;
			}
		}
		if ( $earliest ) {
			return $earliest;
		}
		$u = get_userdata( (int) $persona['user_id'] );
		return $u ? (int) strtotime( get_date_from_gmt( $u->user_registered ) ) : $now;
	}

	/** @internal "This opens for you on …" */
	public static function _prompt( $args, $failing, $post ) {
		if ( empty( $failing['opens_at'] ) ) {
			return $args;
		}
		$at   = (int) $failing['opens_at'];
		$days = (int) ceil( ( $at - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$args['title']      = $days <= 1 ? __( 'This opens for you tomorrow', 'fw' ) : sprintf( _n( 'This opens for you in %d day', 'This opens for you in %d days', $days, 'fw' ), $days );
		$args['message']    = sprintf( __( 'It is part of your membership and becomes available on %s. We will email you when it opens.', 'fw' ), date_i18n( get_option( 'date_format' ), $at ) );
		$args['show_plans'] = false;
		$args['show_login'] = false;
		$args['no_join']    = true; // it is already theirs: nothing to join
		return $args;
	}

	/** @internal */
	public static function _fields( $rule ) {
		?>
		<p class="fw-mbr-field">
			<label for="fw-mbr-rdrip"><?php esc_html_e( 'Opens after (days)', 'fw' ); ?></label>
			<input type="number" min="0" id="fw-mbr-rdrip" name="drip_days" value="<?php echo (int) ( $rule['drip_days'] ?? 0 ); ?>" aria-describedby="fw-mbr-rdrip-d" />
			<span class="description" id="fw-mbr-rdrip-d"><?php esc_html_e( 'Drip: members see it this many days after they joined (0 = at once). For a sequence, give each lesson its own rule: 0, 7, 14…', 'fw' ); ?></span>
		</p>
		<p class="fw-mbr-field">
			<label for="fw-mbr-rdripdate"><?php esc_html_e( 'Opens on (date)', 'fw' ); ?></label>
			<input type="date" id="fw-mbr-rdripdate" name="drip_date" value="<?php echo ! empty( $rule['drip_date'] ) ? esc_attr( substr( $rule['drip_date'], 0, 10 ) ) : ''; ?>" />
		</p>
		<?php
	}

	/**
	 * Items a member will see open, soonest first.
	 *
	 * @param int $user_id
	 * @param int $limit
	 *
	 * @return array[] { post_id, title, url, opens_at }
	 */
	public static function upcoming( $user_id, $limit = 10 ) {
		$persona = FW_Membership_Access::persona_for_user( $user_id );
		$now     = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$out     = array();
		foreach ( FW_Membership_Rules::all() as $rule ) {
			if ( 'active' !== $rule['status'] || ( ! $rule['drip_days'] && ! $rule['drip_date'] ) || ! FW_Membership_Access::satisfies( $rule, $persona ) ) {
				continue;
			}
			$at = self::opens_at( 0, $rule, $persona );
			if ( $at <= $now ) {
				continue;
			}
			foreach ( $rule['targets'] as $t ) {
				if ( 'include' === $t['mode'] && 'post' === $t['type'] && $t['object_id'] && 'publish' === get_post_status( $t['object_id'] ) ) {
					$out[ $t['object_id'] ] = array( 'post_id' => (int) $t['object_id'], 'title' => get_the_title( $t['object_id'] ), 'url' => get_permalink( $t['object_id'] ), 'opens_at' => $at );
				}
			}
		}
		uasort( $out, function ( $a, $b ) {
			return $a['opens_at'] <=> $b['opens_at'];
		} );
		return array_slice( array_values( $out ), 0, $limit );
	}

	/** @internal "Coming up" on the account page. */
	public static function _account( $user ) {
		$items = self::upcoming( $user->ID );
		if ( ! $items ) {
			return;
		}
		?>
		<section class="fw-mbr-account__section" aria-labelledby="fw-mbr-acc-drip">
			<h2 id="fw-mbr-acc-drip"><?php esc_html_e( 'Coming up for you', 'fw' ); ?></h2>
			<ul class="fw-mbr-account__payments">
				<?php foreach ( $items as $it ) : ?>
					<li><span><?php echo esc_html( $it['title'] ); ?></span><span><?php echo esc_html( sprintf( __( 'Opens %s', 'fw' ), date_i18n( get_option( 'date_format' ), $it['opens_at'] ) ) ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/** @internal */
	public static function _email( $catalogue ) {
		$catalogue['drip_opened'] = array(
			'label'   => __( 'New content opened', 'fw' ),
			'to'      => 'member',
			'when'    => __( 'Once a day, when drip content opens for a member.', 'fw' ),
			'subject' => __( 'New for you on {site_name}', 'fw' ),
			'body'    => __( "Hi {first_name},\n\nSomething new is now open to you:\n\n{items}\n\nEnjoy!", 'fw' ),
		);
		return $catalogue;
	}

	/**
	 * The daily job: email each member what opened for them since the last run.
	 *
	 * @param int|null $since Local unix time (default: the last run, or a day ago).
	 *
	 * @return int Emails sent.
	 */
	public static function run( $since = null ) {
		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$since = null !== $since ? (int) $since : (int) get_option( self::LAST, $now - DAY_IN_SECONDS );
		update_option( self::LAST, $now, false );
		$rules = array_filter( FW_Membership_Rules::all(), function ( $r ) {
			return 'active' === $r['status'] && ( $r['drip_days'] || $r['drip_date'] );
		} );
		if ( ! $rules ) {
			return 0;
		}
		global $wpdb;
		$t     = FW_Membership_Installer::table( 'memberships' );
		$in    = "'" . implode( "','", FW_Membership_Memberships::ACCESS_STATUSES ) . "'";
		$users = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$t} WHERE status IN ({$in})" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sent  = 0;
		foreach ( $users as $uid ) {
			$persona = FW_Membership_Access::persona_for_user( $uid );
			$items   = array();
			foreach ( $rules as $rule ) {
				if ( ! FW_Membership_Access::satisfies( $rule, $persona ) ) {
					continue;
				}
				$at = self::opens_at( 0, $rule, $persona );
				if ( $at <= $since || $at > $now ) {
					continue;
				}
				foreach ( $rule['targets'] as $tg ) {
					if ( 'include' === $tg['mode'] && 'post' === $tg['type'] && $tg['object_id'] && 'publish' === get_post_status( $tg['object_id'] ) ) {
						$items[ $tg['object_id'] ] = '- ' . get_the_title( $tg['object_id'] ) . ': ' . get_permalink( $tg['object_id'] );
					}
				}
			}
			if ( $items ) {
				FW_Membership_Emails::send( 'drip_opened', $uid, array( 'items' => implode( "\n", $items ) ) );
				$sent++;
			}
		}
		return $sent;
	}
}
