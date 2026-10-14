<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Membership records and their history. Repository: the only SQL for the
 * memberships and membership_events tables. No hooks and no business rules —
 * FW_Membership_Service decides what a change means and announces it.
 */
class FW_Membership_Memberships {

	/** Every status a membership can be in. Statuses are varchar, whitelisted here. */
	const STATUSES = array( 'pending', 'trialing', 'active', 'past_due', 'paused', 'cancelled', 'expired', 'refunded' );

	/**
	 * Statuses that give access. `cancelled` gives access until ends_at (cancel at
	 * period end); `past_due` keeps access through the grace period.
	 */
	const ACCESS_STATUSES = array( 'trialing', 'active', 'past_due', 'cancelled' );

	/** Where a membership came from. */
	const SOURCES = array( 'manual', 'payment', 'woocommerce', 'group_seat', 'gift', 'import', 'crm_automation', 'registration' );

	/**
	 * @param int $id
	 *
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = FW_Membership_Installer::table( 'memberships' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $row ? self::shape( $row ) : null;
	}

	/**
	 * @param array $args user_id, plan_id, status (string|array), search, orderby, order, per_page, page
	 *
	 * @return array[]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		list( $where, $params, $join ) = self::where( $args );

		$m        = FW_Membership_Installer::table( 'memberships' );
		$orderby  = in_array( $args['orderby'] ?? '', array( 'created_at', 'started_at', 'ends_at', 'renews_at', 'status' ), true ) ? $args['orderby'] : 'created_at';
		$order    = 'ASC' === strtoupper( (string) ( $args['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );

		$sql = "SELECT m.* FROM {$m} m {$join} WHERE {$where} ORDER BY m.{$orderby} {$order}, m.id DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	/**
	 * @param array $args Same filters as query().
	 *
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;
		list( $where, $params, $join ) = self::where( $args );
		$m   = FW_Membership_Installer::table( 'memberships' );
		$sql = "SELECT COUNT(*) FROM {$m} m {$join} WHERE {$where}";

		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @return array status => count
	 */
	public static function counts_by_status() {
		global $wpdb;
		$m   = FW_Membership_Installer::table( 'memberships' );
		$out = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$m} GROUP BY status" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * @return array plan_id => count of memberships that give access
	 */
	public static function counts_by_plan() {
		global $wpdb;
		$m    = FW_Membership_Installer::table( 'memberships' );
		$in   = "'" . implode( "','", self::ACCESS_STATUSES ) . "'";
		$out  = array();
		foreach ( (array) $wpdb->get_results( "SELECT plan_id, COUNT(*) AS n FROM {$m} WHERE status IN ({$in}) GROUP BY plan_id" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[ (int) $r->plan_id ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * Distinct people with at least one membership that gives access.
	 *
	 * @return int
	 */
	public static function count_members() {
		global $wpdb;
		$m  = FW_Membership_Installer::table( 'memberships' );
		$in = "'" . implode( "','", self::ACCESS_STATUSES ) . "'";

		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$m} WHERE status IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Memberships whose time is up: active/cancelled/past_due/trialing with ends_at in the past.
	 *
	 * @param int $limit
	 *
	 * @return array[]
	 */
	public static function due_to_expire( $limit = 200 ) {
		global $wpdb;
		$m  = FW_Membership_Installer::table( 'memberships' );
		$in = "'" . implode( "','", self::ACCESS_STATUSES ) . "'";
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$m} WHERE status IN ({$in}) AND ends_at IS NOT NULL AND ends_at <= %s ORDER BY ends_at ASC LIMIT %d", current_time( 'mysql' ), (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	/**
	 * The membership billed by a payment service subscription.
	 *
	 * @param string $subscription_id
	 *
	 * @return array|null
	 */
	public static function find_by_subscription( $subscription_id ) {
		global $wpdb;
		if ( '' === (string) $subscription_id ) {
			return null;
		}
		$table = FW_Membership_Installer::table( 'memberships' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE gateway_subscription_id = %s ORDER BY id DESC LIMIT 1", (string) $subscription_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * @param array $row
	 *
	 * @return int The new id, or 0.
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$now               = current_time( 'mysql' );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		$ok                = $wpdb->insert( FW_Membership_Installer::table( 'memberships' ), self::clean( $row ) );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int   $id
	 * @param array $row
	 *
	 * @return bool
	 */
	public static function update( $id, array $row ) {
		global $wpdb;
		$row['updated_at'] = current_time( 'mysql' );

		return false !== $wpdb->update( FW_Membership_Installer::table( 'memberships' ), self::clean( $row ), array( 'id' => (int) $id ) );
	}

	/**
	 * Append a history entry. Never updated afterwards.
	 *
	 * @param array $event membership_id, user_id, plan_id, type, status_from, status_to, note, meta
	 */
	public static function log( array $event ) {
		global $wpdb;
		$wpdb->insert( FW_Membership_Installer::table( 'membership_events' ), array(
			'membership_id' => (int) ( $event['membership_id'] ?? 0 ),
			'user_id'       => (int) ( $event['user_id'] ?? 0 ),
			'plan_id'       => (int) ( $event['plan_id'] ?? 0 ),
			'type'          => substr( sanitize_key( (string) ( $event['type'] ?? '' ) ), 0, 40 ),
			'status_from'   => (string) ( $event['status_from'] ?? '' ),
			'status_to'     => (string) ( $event['status_to'] ?? '' ),
			'note'          => (string) ( $event['note'] ?? '' ),
			'meta'          => ! empty( $event['meta'] ) ? wp_json_encode( $event['meta'] ) : null,
			'actor_id'      => (int) ( $event['actor_id'] ?? get_current_user_id() ),
			'created_at'    => current_time( 'mysql' ),
		) );
	}

	/**
	 * @param array $args user_id, membership_id, limit
	 *
	 * @return object[]
	 */
	public static function history( array $args ) {
		global $wpdb;
		$t      = FW_Membership_Installer::table( 'membership_events' );
		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'user_id', 'membership_id' ) as $k ) {
			if ( ! empty( $args[ $k ] ) ) {
				$where[]  = "{$k} = %d";
				$params[] = (int) $args[ $k ];
			}
		}
		$params[] = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC LIMIT %d', $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Delete every membership and history row of a user (WordPress user deleted).
	 *
	 * @param int $user_id
	 */
	public static function delete_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( FW_Membership_Installer::table( 'memberships' ), array( 'user_id' => (int) $user_id ) );
		$wpdb->delete( FW_Membership_Installer::table( 'membership_events' ), array( 'user_id' => (int) $user_id ) );
	}

	/* ---------------------------------------------------------------------- */

	private static function where( array $args ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		$join   = '';

		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'm.user_id = %d';
			$params[] = (int) $args['user_id'];
		}
		if ( ! empty( $args['plan_id'] ) ) {
			$where[]  = 'm.plan_id = %d';
			$params[] = (int) $args['plan_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$statuses = 'access' === $args['status'] ? self::ACCESS_STATUSES : array_intersect( (array) $args['status'], self::STATUSES );
			if ( $statuses ) {
				$where[] = 'm.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
				$params  = array_merge( $params, array_values( $statuses ) );
			}
		}
		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$join     = "INNER JOIN {$wpdb->users} u ON u.ID = m.user_id";
			$like     = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$where[]  = '(u.user_email LIKE %s OR u.user_login LIKE %s OR u.display_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		return array( implode( ' AND ', $where ), $params, $join );
	}

	private static function clean( array $row ) {
		$allowed = array( 'user_id', 'plan_id', 'price_id', 'status', 'source', 'source_ref', 'gateway', 'gateway_subscription_id', 'started_at', 'trial_ends_at', 'renews_at', 'ends_at', 'cancelled_at', 'meta', 'created_at', 'updated_at' );
		$row     = array_intersect_key( $row, array_flip( $allowed ) );
		if ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) {
			$row['meta'] = $row['meta'] ? wp_json_encode( $row['meta'] ) : null;
		}
		return $row;
	}

	private static function shape( array $row ) {
		foreach ( array( 'id', 'user_id', 'plan_id', 'price_id' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$row['meta'] = isset( $row['meta'] ) ? (array) json_decode( (string) $row['meta'], true ) : array();
		return $row;
	}
}
