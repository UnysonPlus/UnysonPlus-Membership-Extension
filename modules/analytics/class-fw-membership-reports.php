<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The numbers: worked out from the memberships, their history and the payments, so
 * they need no separate tracking and are right for the past too.
 *
 *   MRR            what repeating prices bring in per month, normalised (a yearly price
 *                  is 1/12 a month), for memberships with access that are not in a trial
 *   movements      per month: new members, upgrades, downgrades, members lost, failed and
 *                  recovered payments
 *   churn          members lost in a month / members with access at its start
 *   lifetime value paid (minus refunds) per paying member
 *   trial conversion  trials that went on to be paid
 *
 * Amounts are kept per currency, never added across currencies.
 */
class FW_Membership_Reports {

	/** @return float A price's monthly value (0 for one-off and free prices). */
	public static function monthly( array $price ) {
		$per = array( 'day' => 30.4375, 'week' => 30.4375 / 7, 'month' => 1, 'year' => 1 / 12 );
		if ( ! isset( $per[ $price['billing'] ] ) ) {
			return 0.0;
		}
		return (float) $price['amount'] * $per[ $price['billing'] ] / max( 1, (int) $price['interval_count'] );
	}

	/**
	 * Memberships that had access at a moment (local mysql datetime).
	 *
	 * @param string $at
	 * @param bool   $paying_only Leave out trials and memberships without a repeating price.
	 *
	 * @return array[]
	 */
	public static function active_at( $at, $paying_only = false ) {
		global $wpdb;
		$m    = FW_Membership_Installer::table( 'memberships' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, user_id, plan_id, price_id, status, source, started_at, trial_ends_at, ends_at FROM {$m}
			 WHERE started_at IS NOT NULL AND started_at <= %s AND ( ends_at IS NULL OR ends_at > %s ) AND status NOT IN ('pending','refunded')",
			$at,
			$at
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $paying_only ) {
			return $rows;
		}
		return array_values( array_filter( $rows, function ( $r ) use ( $at ) {
			return ! ( $r['trial_ends_at'] && $r['trial_ends_at'] > $at ) && (int) $r['price_id'] > 0;
		} ) );
	}

	/**
	 * MRR at a moment, per currency (and per plan).
	 *
	 * @param string $at local mysql datetime ('' = now)
	 *
	 * @return array { total: currency => amount, plans: plan_id => [ currency => amount ], members: int }
	 */
	public static function mrr( $at = '' ) {
		$at  = '' !== $at ? $at : current_time( 'mysql' );
		$out = array( 'total' => array(), 'plans' => array(), 'members' => 0 );
		foreach ( self::active_at( $at, true ) as $r ) {
			$price = FW_Membership_Plans::get_price( $r['price_id'] );
			$v     = $price ? self::monthly( $price ) : 0.0;
			if ( $v <= 0 ) {
				continue;
			}
			$cur                                   = strtoupper( (string) $price['currency'] );
			$out['total'][ $cur ]                  = ( $out['total'][ $cur ] ?? 0 ) + $v;
			$out['plans'][ $r['plan_id'] ][ $cur ] = ( $out['plans'][ $r['plan_id'] ][ $cur ] ?? 0 ) + $v;
			$out['members']++;
		}
		return $out;
	}

	/** @return string[] The last N month keys (Y-m), oldest first. */
	public static function months( $n = 12 ) {
		$out = array();
		$t   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			$out[] = gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01', $t ) . " -{$i} months" ) );
		}
		return $out;
	}

	/**
	 * Month by month.
	 *
	 * @param int $n Months back.
	 *
	 * @return array[] ym => { start_members, new, upgrades, downgrades, lost, churn (%), failed, recovered,
	 *                 revenue: currency => amount, mrr: currency => amount }
	 */
	public static function monthly_table( $n = 12 ) {
		global $wpdb;
		$e    = FW_Membership_Installer::table( 'membership_events' );
		$p    = FW_Membership_Installer::table( 'payments' );
		$from = self::months( $n )[0] . '-01 00:00:00';
		$out  = array();
		foreach ( self::months( $n ) as $ym ) {
			$start      = $ym . '-01 00:00:00';
			$end        = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +1 month' ) - 1 );
			$out[ $ym ] = array(
				'start_members' => count( self::active_at( $start ) ),
				'new'           => 0,
				'upgrades'      => 0,
				'downgrades'    => 0,
				'lost'          => 0,
				'failed'        => 0,
				'recovered'     => 0,
				'revenue'       => array(),
				'mrr'           => self::mrr( $end )['total'],
			);
		}
		$events = (array) $wpdb->get_results( $wpdb->prepare( "SELECT type, plan_id, status_from, meta, created_at FROM {$e} WHERE created_at >= %s AND type IN ('joined','trial_started','plan_changed','expired','refunded','payment_failed','payment_recovered')", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $events as $ev ) {
			$ym = substr( $ev['created_at'], 0, 7 );
			if ( ! isset( $out[ $ym ] ) ) {
				continue;
			}
			$meta = (array) json_decode( (string) $ev['meta'], true );
			switch ( $ev['type'] ) {
				case 'joined':
				case 'trial_started':
					if ( 'trialing' !== $ev['status_from'] ) {
						$out[ $ym ]['new']++; // a trial turning paid is not a new member
					}
					break;
				case 'plan_changed':
					$old = FW_Membership_Plans::get( (int) ( $meta['previous_plan_id'] ?? 0 ) );
					$new = FW_Membership_Plans::get( (int) $ev['plan_id'] );
					if ( $old && $new ) {
						$diff = self::plan_value( $new ) - self::plan_value( $old );
						if ( $diff > 0 ) {
							$out[ $ym ]['upgrades']++;
						} elseif ( $diff < 0 ) {
							$out[ $ym ]['downgrades']++;
						}
					}
					break;
				case 'expired':
				case 'refunded':
					if ( empty( $meta['replaced'] ) && in_array( $ev['status_from'], FW_Membership_Memberships::ACCESS_STATUSES, true ) ) {
						$out[ $ym ]['lost']++;
					}
					break;
				case 'payment_failed':
					$out[ $ym ]['failed']++;
					break;
				case 'payment_recovered':
					$out[ $ym ]['recovered']++;
					break;
			}
		}
		$pays = (array) $wpdb->get_results( $wpdb->prepare( "SELECT currency, amount, status, created_at FROM {$p} WHERE created_at >= %s AND status IN ('paid','refunded')", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $pays as $pay ) {
			$ym = substr( $pay['created_at'], 0, 7 );
			if ( isset( $out[ $ym ] ) && 'paid' === $pay['status'] ) {
				$out[ $ym ]['revenue'][ $pay['currency'] ] = ( $out[ $ym ]['revenue'][ $pay['currency'] ] ?? 0 ) + (float) $pay['amount'];
			}
		}
		foreach ( $out as $ym => $row ) {
			$out[ $ym ]['churn'] = $row['start_members'] ? round( 100 * $row['lost'] / $row['start_members'], 1 ) : 0.0;
		}
		return $out;
	}

	/** @return float A plan's most common monthly value (for telling up from down). */
	private static function plan_value( array $plan ) {
		$best = 0.0;
		foreach ( $plan['prices'] as $pr ) {
			$best = max( $best, self::monthly( $pr ) );
		}
		return $best;
	}

	/**
	 * Lifetime value: paid minus refunds, per paying member, per currency.
	 *
	 * @return array currency => { total, members, average }
	 */
	public static function ltv() {
		global $wpdb;
		$p    = FW_Membership_Installer::table( 'payments' );
		$rows = (array) $wpdb->get_results( "SELECT currency, SUM(amount) AS total, COUNT(DISTINCT user_id) AS members FROM {$p} WHERE status = 'paid' AND amount > 0 GROUP BY currency", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ $r['currency'] ] = array( 'total' => (float) $r['total'], 'members' => (int) $r['members'], 'average' => $r['members'] ? (float) $r['total'] / (int) $r['members'] : 0.0 );
		}
		return $out;
	}

	/**
	 * Trials started in a window, and how many went on to be paid.
	 *
	 * @param int $days
	 *
	 * @return array { started, converted, ended_unpaid, still_trialing, rate (%) }
	 */
	public static function trials( $days = 180 ) {
		global $wpdb;
		$e     = FW_Membership_Installer::table( 'membership_events' );
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$ids   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT membership_id FROM {$e} WHERE type = 'trial_started' AND created_at >= %s", $since ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out   = array( 'started' => count( $ids ), 'converted' => 0, 'ended_unpaid' => 0, 'still_trialing' => 0, 'rate' => 0.0 );
		foreach ( $ids as $id ) {
			$m = FW_Membership_Memberships::get( $id );
			if ( ! $m ) {
				continue;
			}
			$paid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . FW_Membership_Installer::table( 'payments' ) . " WHERE membership_id = %d AND status = 'paid' AND amount > 0", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( 'trialing' === $m['status'] ) {
				$out['still_trialing']++;
			} elseif ( $paid ) {
				$out['converted']++;
			} else {
				$out['ended_unpaid']++;
			}
		}
		$decided     = $out['converted'] + $out['ended_unpaid'];
		$out['rate'] = $decided ? round( 100 * $out['converted'] / $decided, 1 ) : 0.0;
		return $out;
	}

	/** @return string A money map as text: "USD 120.00 · EUR 40.00". */
	public static function money_list( array $by_currency ) {
		$parts = array();
		foreach ( $by_currency as $cur => $n ) {
			$parts[] = FW_Membership_Plans::money( $n, $cur );
		}
		return $parts ? implode( ' · ', $parts ) : '—';
	}
}
