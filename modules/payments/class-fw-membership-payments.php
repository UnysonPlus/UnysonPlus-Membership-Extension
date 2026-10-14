<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Payments repository: the only SQL for the payments table; no hooks.
 *
 * One row per money movement: the first payment, each renewal, a refund. `status` is
 * pending | paid | failed | refunded | cancelled; `type` is payment | renewal | refund.
 * `gateway_ref` is the payment service's id (a checkout session, an invoice, a charge)
 * and is how webhooks find the row again, so a repeated webhook changes nothing.
 */
class FW_Membership_Payments {

	const STATUSES = array( 'pending', 'paid', 'failed', 'refunded', 'cancelled' );

	/**
	 * @param array $row
	 *
	 * @return int
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$row = self::clean( $row );
		$row['created_at'] = current_time( 'mysql' );
		if ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) {
			$row['meta'] = wp_json_encode( $row['meta'] );
		}
		return $wpdb->insert( FW_Membership_Installer::table( 'payments' ), $row ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int   $id
	 * @param array $row
	 *
	 * @return bool
	 */
	public static function update( $id, array $row ) {
		global $wpdb;
		$row = self::clean( $row );
		if ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) {
			$row['meta'] = wp_json_encode( $row['meta'] );
		}
		return false !== $wpdb->update( FW_Membership_Installer::table( 'payments' ), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * @param int $id
	 *
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t   = FW_Membership_Installer::table( 'payments' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * @param string $gateway
	 * @param string $ref
	 *
	 * @return array|null
	 */
	public static function find_by_ref( $gateway, $ref ) {
		global $wpdb;
		if ( '' === (string) $ref ) {
			return null;
		}
		$t   = FW_Membership_Installer::table( 'payments' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE gateway = %s AND gateway_ref = %s ORDER BY id DESC LIMIT 1", $gateway, $ref ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * @param array $args user_id, membership_id, status, gateway, per_page, page
	 *
	 * @return array[]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::where( $args );
		$per    = max( 1, min( 500, (int) ( $args['per_page'] ?? 25 ) ) );
		$offset = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per );
		$t      = FW_Membership_Installer::table( 'payments' );
		$params[] = $per;
		$params[] = $offset;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	/**
	 * @param array $args Same filters as query().
	 *
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::where( $args );
		$t   = FW_Membership_Installer::table( 'payments' );
		$sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Paid total per currency (refunds subtracted).
	 *
	 * @param string $since mysql datetime, or '' for all time.
	 *
	 * @return array currency => amount
	 */
	public static function totals( $since = '' ) {
		global $wpdb;
		$t   = FW_Membership_Installer::table( 'payments' );
		$sql = "SELECT currency, SUM(CASE WHEN status = 'paid' THEN amount WHEN status = 'refunded' THEN 0 ELSE 0 END) AS total FROM {$t} WHERE type <> 'refund'" . ( '' !== $since ? $wpdb->prepare( ' AND created_at >= %s', $since ) : '' ) . ' GROUP BY currency'; // phpcs:ignore WordPress.DB.PreparedSQL
		$out = array();
		foreach ( (array) $wpdb->get_results( $sql ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[ $r->currency ] = (float) $r->total;
		}
		return $out;
	}

	/**
	 * Does this price renew on its own?
	 *
	 * @param array $price
	 *
	 * @return bool
	 */
	public static function is_recurring( array $price ) {
		return in_array( $price['billing'], array( 'day', 'week', 'month', 'year' ), true );
	}

	/**
	 * "USD 9.00".
	 *
	 * @param float  $amount
	 * @param string $currency
	 *
	 * @return string
	 */
	public static function money( $amount, $currency ) {
		return FW_Membership_Plans::money( $amount, $currency );
	}

	/**
	 * The smallest unit a payment service expects (cents), handling zero-decimal currencies.
	 *
	 * @param float  $amount
	 * @param string $currency
	 *
	 * @return int
	 */
	public static function minor_units( $amount, $currency ) {
		$zero = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		return in_array( strtoupper( $currency ), $zero, true ) ? (int) round( $amount ) : (int) round( $amount * 100 );
	}

	/* ---------------------------------------------------------------------- */

	private static function where( array $args ) {
		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'user_id', 'membership_id' ) as $k ) {
			if ( ! empty( $args[ $k ] ) ) {
				$where[]  = "{$k} = %d";
				$params[] = (int) $args[ $k ];
			}
		}
		foreach ( array( 'status', 'gateway', 'type' ) as $k ) {
			if ( ! empty( $args[ $k ] ) ) {
				$where[]  = "{$k} = %s";
				$params[] = (string) $args[ $k ];
			}
		}
		return array( implode( ' AND ', $where ), $params );
	}

	private static function clean( array $row ) {
		$allowed = array( 'membership_id', 'user_id', 'plan_id', 'price_id', 'type', 'status', 'amount', 'tax', 'currency', 'gateway', 'gateway_ref', 'coupon', 'invoice_number', 'meta' );
		return array_intersect_key( $row, array_flip( $allowed ) );
	}

	private static function shape( array $row ) {
		foreach ( array( 'id', 'membership_id', 'user_id', 'plan_id', 'price_id' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$row['amount'] = (float) $row['amount'];
		$row['tax']    = (float) $row['tax'];
		$row['meta']   = (array) json_decode( (string) $row['meta'], true );
		return $row;
	}
}
