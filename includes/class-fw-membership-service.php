<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The only place a membership changes state, and the only place that fires events.
 *
 * Every public method validates, writes through the repository, appends to the
 * history and fires the event from FW_Membership_Events. Admin screens, REST,
 * payment gateways and the CRM connection all call these methods; nothing else
 * writes a membership's status.
 */
class FW_Membership_Service {

	/** Hourly job that ends memberships whose end date has passed. */
	const CRON_HOOK = 'fw_membership_expire_due';

	/** @var array user_id => plan ids, for this request; cleared on every change. */
	private static $plans_cache = array();

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'expire_due' ) );
		// Remember when each scheduled job last ran (Membership → Home → Scheduled jobs).
		foreach ( (array) apply_filters( 'unysonplus_membership_cron_hooks', array( self::CRON_HOOK, 'fw_mbr_drip_daily', 'fw_mbr_retention_daily' ) ) as $hook ) {
			add_action( $hook, function () use ( $hook ) {
				$last          = (array) get_option( 'fw_ext_membership_cron_last', array() );
				$last[ $hook ] = time();
				update_option( 'fw_ext_membership_cron_last', $last, false );
			}, 0 );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}

		// A deleted WordPress user takes their memberships and history along.
		add_action( 'deleted_user', array( 'FW_Membership_Memberships', 'delete_for_user' ) );
	}

	/**
	 * Who may manage memberships. Filterable so a "membership manager" role can be
	 * given the screens without full site options.
	 *
	 * @return string
	 */
	/** Forget the per-request plan answers (after writing memberships directly). */
	public static function flush_cache() {
		self::$plans_cache = array();
		if ( class_exists( 'FW_Membership_Access' ) ) {
			FW_Membership_Access::flush();
		}
	}

	public static function capability() {
		return (string) apply_filters( 'unysonplus_membership_capability', 'manage_options' );
	}

	/**
	 * Give someone a plan.
	 *
	 * @param int   $user_id
	 * @param int   $plan_id
	 * @param array $args source, source_ref, price_id, status (active|trialing|pending),
	 *                    starts (mysql datetime), ends (mysql datetime|'' for the plan's own duration|'never'),
	 *                    note
	 *
	 * @return int|WP_Error The membership id.
	 */
	public static function grant( $user_id, $plan_id, array $args = array() ) {
		$user_id = (int) $user_id;
		$plan    = FW_Membership_Plans::get( $plan_id );

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'fw_mbr_no_user', __( 'That person does not have a WordPress account.', 'fw' ) );
		}
		if ( ! $plan ) {
			return new WP_Error( 'fw_mbr_no_plan', __( 'That plan does not exist.', 'fw' ) );
		}
		if ( 'manual' !== ( $args['source'] ?? 'manual' ) && $plan['signup_limit'] && FW_Membership_Memberships::count( array( 'plan_id' => $plan['id'], 'status' => 'access' ) ) >= $plan['signup_limit'] ) {
			return new WP_Error( 'fw_mbr_full', __( 'This plan is full.', 'fw' ) );
		}

		$existing = FW_Membership_Memberships::query( array( 'user_id' => $user_id, 'plan_id' => $plan['id'], 'status' => 'access', 'per_page' => 1 ) );
		if ( $existing ) {
			return new WP_Error( 'fw_mbr_already', __( 'This person already holds that plan. Extend it instead.', 'fw' ) );
		}

		$status = in_array( $args['status'] ?? 'active', array( 'active', 'trialing', 'pending' ), true ) ? ( $args['status'] ?? 'active' ) : 'active';
		$starts = ! empty( $args['starts'] ) ? (string) $args['starts'] : current_time( 'mysql' );
		$ends   = self::end_date( $plan, $starts, $args['ends'] ?? '' );
		$source = in_array( $args['source'] ?? 'manual', FW_Membership_Memberships::SOURCES, true ) ? ( $args['source'] ?? 'manual' ) : 'manual';

		$id = FW_Membership_Memberships::insert( array(
			'user_id'    => $user_id,
			'plan_id'    => $plan['id'],
			'price_id'   => (int) ( $args['price_id'] ?? 0 ),
			'status'     => $status,
			'source'     => $source,
			'source_ref' => (string) ( $args['source_ref'] ?? '' ),
			'started_at' => $starts,
			'ends_at'    => $ends,
		) );
		if ( ! $id ) {
			return new WP_Error( 'fw_mbr_db', __( 'The membership could not be saved.', 'fw' ) );
		}

		// A pending membership (awaiting email verification or approval) has not
		// started yet: it is announced as "joined" when it is activated.
		if ( 'pending' !== $status ) {
			$event = 'trialing' === $status ? 'trial_started' : 'joined';
			self::record( $id, $event, '', $status, (string) ( $args['note'] ?? '' ), array(), array( 'notify' => isset( $args['notify'] ) ? (bool) $args['notify'] : 'manual' !== $source ) );
		} else {
			self::record( $id, 'pending', '', 'pending', (string) ( $args['note'] ?? '' ), array(), array( 'notify' => false ) );
		}

		return $id;
	}

	/**
	 * Start a pending membership (after email verification or approval). The length
	 * runs from now, not from when the person signed up.
	 *
	 * @param int  $id
	 * @param bool $notify Send the welcome email.
	 *
	 * @return true|WP_Error
	 */
	public static function activate( $id, $notify = true, array $args = array() ) {
		$m = FW_Membership_Memberships::get( $id );
		if ( ! $m ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Membership not found.', 'fw' ) );
		}
		if ( 'pending' !== $m['status'] ) {
			return true;
		}
		$plan   = FW_Membership_Plans::get( $m['plan_id'] );
		$now    = current_time( 'mysql' );
		$status = 'trialing' === ( $args['status'] ?? '' ) ? 'trialing' : 'active';
		$row    = array(
			'status'     => $status,
			'started_at' => $now,
			'ends_at'    => array_key_exists( 'ends', $args ) ? $args['ends'] : ( $plan ? self::end_date( $plan, $now, '' ) : $m['ends_at'] ),
		);
		foreach ( array( 'renews_at', 'trial_ends_at', 'gateway', 'gateway_subscription_id' ) as $k ) {
			if ( array_key_exists( $k, $args ) ) {
				$row[ $k ] = $args[ $k ];
			}
		}
		FW_Membership_Memberships::update( $id, $row );
		self::record( $id, 'trialing' === $status ? 'trial_started' : 'joined', 'pending', $status, '', array(), array( 'notify' => $notify ) );

		return true;
	}

	/**
	 * A renewal was paid: access runs to the new date. A past-due membership is
	 * recovered (event payment_recovered), anything else is renewed.
	 *
	 * @param int         $id
	 * @param string|null $ends      mysql datetime the paid period runs to (null: no end).
	 * @param string|null $renews_at mysql datetime of the next renewal.
	 *
	 * @return true|WP_Error
	 */
	public static function renew_until( $id, $ends, $renews_at = null ) {
		$m = FW_Membership_Memberships::get( $id );
		if ( ! $m ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Membership not found.', 'fw' ) );
		}
		$was = $m['status'];
		FW_Membership_Memberships::update( $id, array( 'status' => 'active', 'ends_at' => $ends, 'renews_at' => $renews_at ) );
		self::record( $id, 'past_due' === $was ? 'payment_recovered' : 'renewed', $was, 'active', '', array( 'ends_at' => $ends ) );
		return true;
	}

	/**
	 * A renewal payment failed: the membership is past due. Access continues through
	 * the grace period the payment service gives; the service ends it if it never pays.
	 *
	 * @param int    $id
	 * @param string $note
	 *
	 * @return true|WP_Error
	 */
	public static function payment_failed( $id, $note = '' ) {
		$m = FW_Membership_Memberships::get( $id );
		if ( ! $m ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Membership not found.', 'fw' ) );
		}
		if ( 'past_due' === $m['status'] ) {
			return true;
		}
		FW_Membership_Memberships::update( $id, array( 'status' => 'past_due' ) );
		self::record( $id, 'payment_failed', $m['status'], 'past_due', $note );
		return true;
	}

	/**
	 * The payment was refunded: access ends now.
	 *
	 * @param int    $id
	 * @param string $note
	 *
	 * @return true|WP_Error
	 */
	public static function refund( $id, $note = '' ) {
		$m = FW_Membership_Memberships::get( $id );
		if ( ! $m ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Membership not found.', 'fw' ) );
		}
		FW_Membership_Memberships::update( $id, array( 'status' => 'refunded', 'ends_at' => current_time( 'mysql' ) ) );
		self::record( $id, 'refunded', $m['status'], 'refunded', $note );
		return true;
	}

	/**
	 * Store billing details on a membership (no status change, no event).
	 *
	 * @param int   $id
	 * @param array $fields gateway, gateway_subscription_id, renews_at, price_id
	 */
	public static function set_billing( $id, array $fields ) {
		FW_Membership_Memberships::update( $id, array_intersect_key( $fields, array_flip( array( 'gateway', 'gateway_subscription_id', 'renews_at', 'price_id' ) ) ) );
	}

	/**
	 * Start every pending membership a user holds.
	 *
	 * @param int  $user_id
	 * @param bool $notify
	 */
	public static function activate_pending( $user_id, $notify = true ) {
		foreach ( FW_Membership_Memberships::query( array( 'user_id' => (int) $user_id, 'status' => array( 'pending' ), 'per_page' => 50 ) ) as $m ) {
			if ( 'payment' === $m['source'] ) {
				continue; // waits for its payment, not for the email address
			}
			self::activate( $m['id'], $notify );
		}
	}

	/**
	 * Push the end date out: by the plan's own duration, by a set period, or to a date.
	 *
	 * @param int    $id
	 * @param string $until A mysql datetime, '+1 month' style period, or '' for the plan's duration.
	 *
	 * @return true|WP_Error
	 */
	public static function extend( $id, $until = '' ) {
		$m = FW_Membership_Memberships::get( $id );
		if ( ! $m ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Membership not found.', 'fw' ) );
		}
		$plan = FW_Membership_Plans::get( $m['plan_id'] );
		$from = $m['ends_at'] && strtotime( $m['ends_at'] ) > current_time( 'timestamp' ) ? $m['ends_at'] : current_time( 'mysql' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

		if ( '' === $until ) {
			$ends = $plan ? self::end_date( $plan, $from, '' ) : null;
		} elseif ( 'never' === $until ) {
			$ends = null;
		} elseif ( '+' === substr( $until, 0, 1 ) ) {
			$ends = gmdate( 'Y-m-d H:i:s', strtotime( $until, strtotime( $from ) ) );
		} else {
			$ends = gmdate( 'Y-m-d 23:59:59', strtotime( $until ) );
		}

		$was = $m['status'];
		$now = in_array( $was, array( 'expired', 'cancelled', 'refunded' ), true ) ? 'active' : $was;
		FW_Membership_Memberships::update( $id, array( 'ends_at' => $ends, 'status' => $now, 'cancelled_at' => 'active' === $now && 'cancelled' === $was ? null : $m['cancelled_at'] ) );
		self::record( $id, 'renewed', $was, $now, '', array( 'ends_at' => $ends ) );

		return true;
	}

	/**
	 * Cancel. By default access continues to the end date ("cancel at period end");
	 * with $now = true, or when there is no end date, it ends immediately.
	 *
	 * @param int  $id
	 * @param bool $now
	 *
	 * @return true|WP_Error
	 */
	public static function cancel( $id, $now = false ) {
		$m = FW_Membership_Memberships::get( $id );
		if ( ! $m ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Membership not found.', 'fw' ) );
		}
		if ( $now || ! $m['ends_at'] ) {
			return self::expire( $id, __( 'Cancelled with immediate effect.', 'fw' ) );
		}
		FW_Membership_Memberships::update( $id, array( 'status' => 'cancelled', 'cancelled_at' => current_time( 'mysql' ) ) );
		self::record( $id, 'cancelled', $m['status'], 'cancelled' );

		return true;
	}

	/**
	 * Pause a membership until a date: no access meanwhile. On resume, a membership
	 * without a subscription gets the paused days added to its end date; one with a
	 * subscription follows the payment service's dates (it renews again after the pause).
	 *
	 * @param int    $id
	 * @param string $until mysql datetime (local)
	 * @param string $note
	 *
	 * @return true|WP_Error
	 */
	public static function pause( $id, $until, $note = '' ) {
		$m = FW_Membership_Memberships::get( $id );
		if ( ! $m ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Membership not found.', 'fw' ) );
		}
		if ( ! in_array( $m['status'], array( 'active', 'trialing' ), true ) ) {
			return new WP_Error( 'fw_mbr_pause', __( 'Only an active membership can be paused.', 'fw' ) );
		}
		$meta                 = $m['meta'];
		$meta['paused_from']  = current_time( 'mysql' );
		$meta['paused_until'] = (string) $until;
		$meta['paused_was']   = $m['status'];
		FW_Membership_Memberships::update( $id, array( 'status' => 'paused', 'meta' => $meta ) );
		self::record( $id, 'paused', $m['status'], 'paused', $note, array( 'until' => (string) $until ) );
		return true;
	}

	/**
	 * End a pause (on its date, or early).
	 *
	 * @param int $id
	 *
	 * @return true|WP_Error
	 */
	public static function resume( $id ) {
		$m = FW_Membership_Memberships::get( $id );
		if ( ! $m || 'paused' !== $m['status'] ) {
			return $m ? true : new WP_Error( 'fw_mbr_not_found', __( 'Membership not found.', 'fw' ) );
		}
		$meta = $m['meta'];
		$row  = array( 'status' => in_array( $meta['paused_was'] ?? '', array( 'active', 'trialing' ), true ) ? $meta['paused_was'] : 'active' );
		if ( '' === (string) $m['gateway_subscription_id'] && $m['ends_at'] && ! empty( $meta['paused_from'] ) ) {
			$paused       = max( 0, current_time( 'timestamp' ) - strtotime( $meta['paused_from'] ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			$row['ends_at'] = gmdate( 'Y-m-d H:i:s', strtotime( $m['ends_at'] ) + $paused );
		}
		unset( $meta['paused_from'], $meta['paused_until'], $meta['paused_was'] );
		$row['meta'] = $meta;
		FW_Membership_Memberships::update( $id, $row );
		self::record( $id, 'resumed', 'paused', $row['status'] );
		return true;
	}

	/**
	 * End access now.
	 *
	 * @param int    $id
	 * @param string $note
	 * @param array  $context Added to the event context (replaced: true = a plan change, no "ended" email).
	 *
	 * @return true|WP_Error
	 */
	public static function expire( $id, $note = '', array $context = array() ) {
		$m = FW_Membership_Memberships::get( $id );
		if ( ! $m ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Membership not found.', 'fw' ) );
		}
		if ( 'expired' === $m['status'] ) {
			return true;
		}
		$ends = $m['ends_at'] && strtotime( $m['ends_at'] ) < current_time( 'timestamp' ) ? $m['ends_at'] : current_time( 'mysql' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		FW_Membership_Memberships::update( $id, array( 'status' => 'expired', 'ends_at' => $ends ) );
		self::record( $id, 'expired', $m['status'], 'expired', $note, array(), $context );

		return true;
	}

	/**
	 * Make a cancelled or expired membership active again, keeping its end date if it
	 * is still in the future (otherwise a fresh period from the plan's duration).
	 *
	 * @param int $id
	 *
	 * @return true|WP_Error
	 */
	public static function reactivate( $id ) {
		$m = FW_Membership_Memberships::get( $id );
		if ( ! $m ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Membership not found.', 'fw' ) );
		}
		$others = FW_Membership_Memberships::query( array( 'user_id' => $m['user_id'], 'plan_id' => $m['plan_id'], 'status' => 'access', 'per_page' => 2 ) );
		foreach ( $others as $o ) {
			if ( $o['id'] !== $m['id'] && 'cancelled' !== $o['status'] ) {
				return new WP_Error( 'fw_mbr_already', __( 'This person already holds that plan through another membership.', 'fw' ) );
			}
		}
		$ends = $m['ends_at'];
		if ( ! $ends || strtotime( $ends ) <= current_time( 'timestamp' ) ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			$plan = FW_Membership_Plans::get( $m['plan_id'] );
			$ends = $plan ? self::end_date( $plan, current_time( 'mysql' ), '' ) : null;
		}
		FW_Membership_Memberships::update( $id, array( 'status' => 'active', 'ends_at' => $ends, 'cancelled_at' => null ) );
		self::record( $id, 'reactivated', $m['status'], 'active' );

		return true;
	}

	/**
	 * Move a membership to another plan, keeping its dates.
	 *
	 * @param int $id
	 * @param int $plan_id
	 *
	 * @return true|WP_Error
	 */
	public static function change_plan( $id, $plan_id ) {
		$m    = FW_Membership_Memberships::get( $id );
		$plan = FW_Membership_Plans::get( $plan_id );
		if ( ! $m || ! $plan ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Membership or plan not found.', 'fw' ) );
		}
		if ( $m['plan_id'] === $plan['id'] ) {
			return true;
		}
		FW_Membership_Memberships::update( $id, array( 'plan_id' => $plan['id'], 'price_id' => 0 ) );
		self::record( $id, 'plan_changed', $m['status'], $m['status'], '', array(), array( 'previous_plan_id' => $m['plan_id'] ) );

		return true;
	}

	/**
	 * The plans a user holds right now (including the plans those plans inherit),
	 * highest priority first. The access engine builds on this.
	 *
	 * @param int $user_id
	 *
	 * @return int[] Plan ids.
	 */
	public static function user_plans( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		if ( isset( self::$plans_cache[ $user_id ] ) ) {
			return self::$plans_cache[ $user_id ];
		}

		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$plans = array();
		foreach ( FW_Membership_Memberships::query( array( 'user_id' => $user_id, 'status' => 'access', 'per_page' => 100 ) ) as $m ) {
			if ( $m['ends_at'] && strtotime( $m['ends_at'] ) <= $now ) {
				continue; // The hourly job has not caught it yet; it no longer counts.
			}
			$plans = array_merge( $plans, FW_Membership_Plans::lineage( $m['plan_id'] ) );
		}
		$plans = array_values( array_unique( $plans ) );
		usort( $plans, function ( $a, $b ) {
			$pa = FW_Membership_Plans::get( $a );
			$pb = FW_Membership_Plans::get( $b );
			return ( $pb ? $pb['priority'] : 0 ) <=> ( $pa ? $pa['priority'] : 0 );
		} );

		return self::$plans_cache[ $user_id ] = $plans;
	}

	/** @internal Cron: end every membership whose end date has passed. */
	public static function expire_due() {
		foreach ( FW_Membership_Memberships::due_to_expire() as $m ) {
			self::expire( $m['id'], __( 'Reached its end date.', 'fw' ) );
		}
	}

	/* ---------------------------------------------------------------------- */

	/**
	 * @param array  $plan
	 * @param string $from mysql datetime
	 * @param string $ends '' = the plan's own duration, 'never' = no end, or a date
	 *
	 * @return string|null
	 */
	public static function end_date( array $plan, $from, $ends ) {
		if ( 'never' === $ends ) {
			return null;
		}
		if ( '' !== (string) $ends ) {
			return gmdate( 'Y-m-d 23:59:59', strtotime( (string) $ends ) );
		}
		if ( ! empty( $plan['fixed_end_date'] ) ) {
			return $plan['fixed_end_date'];
		}
		if ( $plan['duration_value'] && $plan['duration_unit'] ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( '+' . $plan['duration_value'] . ' ' . $plan['duration_unit'], strtotime( $from ) ) );
		}
		return null;
	}

	/**
	 * History + event, in that order, so a listener reading the history sees it.
	 */
	private static function record( $id, $event, $from, $to, $note = '', array $meta = array(), array $extra = array() ) {
		$m = FW_Membership_Memberships::get( $id );
		if ( ! $m ) {
			return;
		}
		unset( self::$plans_cache[ $m['user_id'] ] );
		FW_Membership_Memberships::log( array(
			'membership_id' => $m['id'],
			'user_id'       => $m['user_id'],
			'plan_id'       => $m['plan_id'],
			'type'          => $event,
			'status_from'   => $from,
			'status_to'     => $to,
			'note'          => $note,
			'meta'          => array_merge( $meta, $extra ),
		) );

		FW_Membership_Events::fire( $event, $m, array_merge( array(
			'from'     => $from,
			'to'       => $to,
			'plan'     => FW_Membership_Plans::get( $m['plan_id'] ),
			'actor_id' => get_current_user_id(),
			'note'     => $note,
		), $extra ) );
	}
}
