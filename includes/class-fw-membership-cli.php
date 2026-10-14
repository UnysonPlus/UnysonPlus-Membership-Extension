<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Manage memberships from the command line.
 *
 * ## EXAMPLES
 *
 *     wp membership list --plan=gold --status=access
 *     wp membership grant jane@example.com gold --ends=2027-01-31
 *     wp membership extend 42 --until="+1 month"
 *     wp membership expire 42 --note="Refunded by phone"
 *     wp membership reconcile --dry-run
 */
class FW_Membership_CLI {

	/**
	 * List memberships.
	 *
	 * ## OPTIONS
	 *
	 * [--plan=<plan>]
	 * : Plan id or slug.
	 *
	 * [--status=<status>]
	 * : A status (active, trialing, past_due, paused, cancelled, expired, refunded, pending), or "access".
	 *
	 * [--user=<user>]
	 * : User id, email or login.
	 *
	 * [--format=<format>]
	 * : table, csv, json or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$q = array( 'per_page' => 500 );
		if ( ! empty( $assoc['plan'] ) ) {
			$q['plan_id'] = $this->plan( $assoc['plan'] )['id'];
		}
		if ( ! empty( $assoc['status'] ) ) {
			$q['status'] = 'access' === $assoc['status'] ? 'access' : array( $assoc['status'] );
		}
		if ( ! empty( $assoc['user'] ) ) {
			$q['user_id'] = $this->user( $assoc['user'] )->ID;
		}
		$rows = array();
		foreach ( FW_Membership_Memberships::query( $q ) as $m ) {
			$u      = get_userdata( $m['user_id'] );
			$plan   = FW_Membership_Plans::get( $m['plan_id'] );
			$rows[] = array(
				'id'      => $m['id'],
				'user'    => $u ? $u->user_email : '#' . $m['user_id'],
				'plan'    => $plan ? $plan['slug'] : '#' . $m['plan_id'],
				'status'  => $m['status'],
				'source'  => $m['source'],
				'started' => (string) $m['started_at'],
				'ends'    => (string) $m['ends_at'],
				'gateway' => trim( $m['gateway'] . ' ' . $m['gateway_subscription_id'] ),
			);
		}
		if ( 'count' === ( $assoc['format'] ?? '' ) ) {
			WP_CLI::line( (string) count( $rows ) );
			return;
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'user', 'plan', 'status', 'source', 'started', 'ends', 'gateway' ) );
	}

	/**
	 * Give someone a plan.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User id, email or login.
	 *
	 * <plan>
	 * : Plan id or slug.
	 *
	 * [--ends=<date>]
	 * : An end date (Y-m-d), "never", or empty for the plan's own length.
	 *
	 * [--note=<note>]
	 * : A note for the history.
	 *
	 * [--notify]
	 * : Send the welcome email.
	 */
	public function grant( $args, $assoc ) {
		$u    = $this->user( $args[0] );
		$plan = $this->plan( $args[1] );
		$r    = FW_Membership_Service::grant( $u->ID, $plan['id'], array( 'source' => 'manual', 'ends' => (string) ( $assoc['ends'] ?? '' ), 'note' => (string) ( $assoc['note'] ?? '' ), 'notify' => ! empty( $assoc['notify'] ) ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Membership %d: %s now has %s.', $r, $u->user_email, $plan['name'] ) );
	}

	/**
	 * Push a membership's end date out.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Membership id.
	 *
	 * [--until=<when>]
	 * : A date (Y-m-d), a period like "+1 month", "never", or empty for the plan's length.
	 */
	public function extend( $args, $assoc ) {
		$r = FW_Membership_Service::extend( (int) $args[0], (string) ( $assoc['until'] ?? '' ) );
		is_wp_error( $r ) ? WP_CLI::error( $r->get_error_message() ) : WP_CLI::success( 'Extended. Ends: ' . ( FW_Membership_Memberships::get( (int) $args[0] )['ends_at'] ?? 'never' ) );
	}

	/**
	 * End a membership now (its subscription stops too).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Membership id.
	 *
	 * [--note=<note>]
	 * : A note for the history.
	 */
	public function expire( $args, $assoc ) {
		$r = FW_Membership_Service::expire( (int) $args[0], (string) ( $assoc['note'] ?? '' ) );
		is_wp_error( $r ) ? WP_CLI::error( $r->get_error_message() ) : WP_CLI::success( 'Ended.' );
	}

	/**
	 * List the plans.
	 */
	public function plans( $args, $assoc ) {
		$rows = array();
		foreach ( FW_Membership_Plans::all() as $p ) {
			$rows[] = array( 'id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'status' => $p['status'], 'prices' => implode( ', ', array_map( array( 'FW_Membership_Plans', 'price_label' ), $p['prices'] ) ) );
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'slug', 'name', 'status', 'prices' ) );
	}

	/**
	 * Bring memberships in step: end the ones past their date, and check every
	 * subscription's status with its payment service (in case a notification was missed).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without changing it.
	 */
	public function reconcile( $args, $assoc ) {
		$dry = ! empty( $assoc['dry-run'] );
		$due = FW_Membership_Memberships::due_to_expire( 500 );
		WP_CLI::line( sprintf( '%d past their end date.', count( $due ) ) );
		if ( ! $dry ) {
			FW_Membership_Service::expire_due();
		}
		$fixed = 0;
		foreach ( FW_Membership_Memberships::query( array( 'status' => array( 'active', 'trialing', 'past_due', 'cancelled', 'paused' ), 'per_page' => 2000 ) ) as $m ) {
			if ( '' === (string) $m['gateway_subscription_id'] || ! class_exists( 'FW_Membership_Gateways' ) ) {
				continue;
			}
			$remote = self::remote_status( $m );
			if ( null === $remote || $remote === $m['status'] ) {
				continue;
			}
			WP_CLI::line( sprintf( '#%d %s: here %s, at %s %s.', $m['id'], $m['gateway_subscription_id'], $m['status'], $m['gateway'], $remote ) );
			if ( $dry ) {
				continue;
			}
			if ( 'expired' === $remote ) {
				FW_Membership_Payments_Flow::ended( $m );
			} elseif ( 'past_due' === $remote ) {
				FW_Membership_Service::payment_failed( $m['id'], 'Found by reconcile.' );
			} elseif ( 'cancelled' === $remote ) {
				FW_Membership_Service::cancel( $m['id'] );
			} elseif ( in_array( $remote, array( 'active', 'trialing' ), true ) && in_array( $m['status'], array( 'past_due', 'cancelled' ), true ) ) {
				FW_Membership_Service::reactivate( $m['id'] );
			}
			$fixed++;
		}
		WP_CLI::success( sprintf( '%d subscription(s) %s.', $fixed, $dry ? 'would change' : 'brought in step' ) );
	}

	/** @return string|null What the payment service says, in Membership's words. */
	private static function remote_status( array $m ) {
		$g = FW_Membership_Gateways::get( $m['gateway'] );
		if ( ! $g || ! method_exists( $g, 'api' ) ) {
			return null;
		}
		if ( 'stripe' === $m['gateway'] ) {
			$s = $g->api( 'GET', 'subscriptions/' . $m['gateway_subscription_id'] );
			if ( is_wp_error( $s ) ) {
				return null;
			}
			if ( ! empty( $s['pause_collection'] ) ) {
				return 'paused';
			}
			if ( ! empty( $s['cancel_at_period_end'] ) && 'active' === ( $s['status'] ?? '' ) ) {
				return 'cancelled';
			}
			$map = array( 'active' => 'active', 'trialing' => 'trialing', 'past_due' => 'past_due', 'unpaid' => 'past_due', 'canceled' => 'expired', 'incomplete_expired' => 'expired' );
			return $map[ $s['status'] ?? '' ] ?? null;
		}
		if ( 'paypal' === $m['gateway'] ) {
			$s = $g->api( 'GET', 'v1/billing/subscriptions/' . rawurlencode( $m['gateway_subscription_id'] ) );
			if ( is_wp_error( $s ) ) {
				return null;
			}
			$map = array( 'ACTIVE' => 'active', 'SUSPENDED' => 'paused' === $m['status'] ? 'paused' : 'past_due', 'CANCELLED' => strtotime( (string) $m['renews_at'] ) > current_time( 'timestamp' ) ? 'cancelled' : 'expired', 'EXPIRED' => 'expired' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			return $map[ $s['status'] ?? '' ] ?? null;
		}
		return null;
	}

	private function user( $ref ) {
		$u = is_numeric( $ref ) ? get_userdata( (int) $ref ) : ( is_email( $ref ) ? get_user_by( 'email', $ref ) : get_user_by( 'login', $ref ) );
		if ( ! $u ) {
			WP_CLI::error( "No user {$ref}." );
		}
		return $u;
	}

	private function plan( $ref ) {
		$p = is_numeric( $ref ) ? FW_Membership_Plans::get( (int) $ref ) : FW_Membership_Plans::get_by_slug( (string) $ref );
		if ( ! $p ) {
			WP_CLI::error( "No plan {$ref}." );
		}
		return $p;
	}
}
