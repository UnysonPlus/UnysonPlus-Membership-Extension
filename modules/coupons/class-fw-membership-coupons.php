<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Coupons: table fw_mbr_coupons, the discount in a checkout quote, the matching Stripe
 * coupon, and use counting.
 *
 *   type      percent | fixed (fixed is in the price's currency)
 *   duration  once (the first payment) | forever (every renewal too) | repeating
 *             (the first `months` months)
 *   plans     plan ids it works for (empty = every plan)
 *   max_uses  0 = no limit; uses counts completed checkouts
 *   per_user  how many times one person may use it (0 = no limit)
 *
 * A code is matched without regard to case. Archived coupons stop working but stay on
 * record (payments keep their code).
 */
class FW_Membership_Coupons {

	const VERSION = '1.0.0';

	public static function init() {
		FW_Membership_Installer::module_tables( 'coupons', self::VERSION, array( __CLASS__, 'schema' ), array( 'coupons' ) );
		add_filter( 'unysonplus_membership_quote', array( __CLASS__, '_quote' ), 10, 4 );
		add_filter( 'unysonplus_membership_stripe_session', array( __CLASS__, '_stripe_session' ), 10, 4 );
		add_action( 'unysonplus_membership_checkout_completed', array( __CLASS__, '_completed' ), 10, 4 );
	}

	/** @internal */
	public static function schema( $collate ) {
		$t = FW_Membership_Installer::table( 'coupons' );
		return array( "CREATE TABLE {$t} (
	id bigint(20) unsigned NOT NULL auto_increment,
	code varchar(100) NOT NULL default '',
	description varchar(190) NOT NULL default '',
	type varchar(10) NOT NULL default 'percent',
	amount decimal(13,2) NOT NULL default 0.00,
	currency varchar(3) NOT NULL default '',
	duration varchar(10) NOT NULL default 'once',
	months int(11) unsigned NOT NULL default 0,
	plans longtext,
	max_uses int(11) unsigned NOT NULL default 0,
	per_user int(11) unsigned NOT NULL default 1,
	uses int(11) unsigned NOT NULL default 0,
	starts_at datetime default NULL,
	expires_at datetime default NULL,
	status varchar(20) NOT NULL default 'active',
	batch varchar(60) NOT NULL default '',
	gateway_refs longtext,
	created_at datetime default NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY code (code),
	KEY status (status),
	KEY batch (batch)
) {$collate};" );
	}

	/* ---------------------------------------------------------------------- *
	 * Repository
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $code
	 *
	 * @return array|null
	 */
	public static function get_by_code( $code ) {
		global $wpdb;
		$code = self::clean_code( $code );
		if ( '' === $code ) {
			return null;
		}
		$t   = FW_Membership_Installer::table( 'coupons' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * @param int $id
	 *
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t   = FW_Membership_Installer::table( 'coupons' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * @param array $args status, search, batch, per_page, page
	 *
	 * @return array[]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::where( $args );
		$per      = max( 1, min( 500, (int) ( $args['per_page'] ?? 50 ) ) );
		$params[] = $per;
		$params[] = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per );
		$t        = FW_Membership_Installer::table( 'coupons' );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	/**
	 * @param array $args
	 *
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::where( $args );
		$t   = FW_Membership_Installer::table( 'coupons' );
		$sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Create or update a coupon.
	 *
	 * @param array $data
	 * @param int   $id
	 *
	 * @return int|WP_Error
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;
		$id   = (int) $id;
		$code = self::clean_code( $data['code'] ?? '' );
		if ( '' === $code ) {
			return new WP_Error( 'fw_mbr_coupon_code', __( 'A coupon needs a code (letters, numbers, - and _).', 'fw' ) );
		}
		$taken = self::get_by_code( $code );
		if ( $taken && $taken['id'] !== $id ) {
			return new WP_Error( 'fw_mbr_coupon_taken', sprintf( __( 'The code %s is already used.', 'fw' ), $code ) );
		}
		$type   = 'fixed' === ( $data['type'] ?? '' ) ? 'fixed' : 'percent';
		$amount = round( max( 0, (float) ( $data['amount'] ?? 0 ) ), 2 );
		if ( $amount <= 0 || ( 'percent' === $type && $amount > 100 ) ) {
			return new WP_Error( 'fw_mbr_coupon_amount', __( 'Enter a discount above 0 (at most 100 for a percentage).', 'fw' ) );
		}
		$duration = in_array( $data['duration'] ?? '', array( 'once', 'forever', 'repeating' ), true ) ? $data['duration'] : 'once';
		$row      = array(
			'code'        => $code,
			'description' => sanitize_text_field( (string) ( $data['description'] ?? '' ) ),
			'type'        => $type,
			'amount'      => $amount,
			'currency'    => 'fixed' === $type ? strtoupper( substr( preg_replace( '/[^a-z]/i', '', (string) ( $data['currency'] ?? FW_Membership_Gateways::currency() ) ), 0, 3 ) ) : '',
			'duration'    => $duration,
			'months'      => 'repeating' === $duration ? max( 1, (int) ( $data['months'] ?? 1 ) ) : 0,
			'plans'       => wp_json_encode( array_values( array_filter( array_map( 'intval', (array) ( $data['plans'] ?? array() ) ) ) ) ),
			'max_uses'    => max( 0, (int) ( $data['max_uses'] ?? 0 ) ),
			'per_user'    => max( 0, (int) ( $data['per_user'] ?? 1 ) ),
			'starts_at'   => ! empty( $data['starts_at'] ) ? gmdate( 'Y-m-d 00:00:00', strtotime( (string) $data['starts_at'] ) ) : null,
			'expires_at'  => ! empty( $data['expires_at'] ) ? gmdate( 'Y-m-d 23:59:59', strtotime( (string) $data['expires_at'] ) ) : null,
			'status'      => 'archived' === ( $data['status'] ?? '' ) ? 'archived' : 'active',
			'batch'       => sanitize_key( (string) ( $data['batch'] ?? '' ) ),
		);
		$t = FW_Membership_Installer::table( 'coupons' );
		if ( $id ) {
			$old = self::get( $id );
			// Terms changed: a Stripe coupon made for the old terms no longer matches.
			foreach ( array( 'type', 'amount', 'currency', 'duration', 'months' ) as $k ) {
				if ( $old && (string) $old[ $k ] !== (string) $row[ $k ] && (float) $old[ $k ] !== (float) $row[ $k ] ) {
					$row['gateway_refs'] = null;
					break;
				}
			}
			$ok = $wpdb->update( $t, $row, array( 'id' => $id ) );
		} else {
			$row['created_at'] = current_time( 'mysql' );
			$ok                = $wpdb->insert( $t, $row );
			$id                = (int) $wpdb->insert_id;
		}
		return false === $ok ? new WP_Error( 'fw_mbr_db', __( 'The coupon could not be saved.', 'fw' ) ) : $id;
	}

	/**
	 * Make many single-use codes at once.
	 *
	 * @param array  $data   Coupon terms (as save()), without code.
	 * @param int    $count
	 * @param string $prefix
	 *
	 * @return string[]|WP_Error The codes made; batch name in $data['batch'] or generated.
	 */
	public static function generate( array $data, $count, $prefix = '' ) {
		$count  = max( 1, min( 1000, (int) $count ) );
		$prefix = self::clean_code( $prefix );
		$batch  = sanitize_key( (string) ( $data['batch'] ?? '' ) );
		$batch  = '' !== $batch ? $batch : 'batch-' . gmdate( 'Ymd-His' );
		$codes  = array();
		$tries  = 0;
		while ( count( $codes ) < $count && $tries < $count * 3 ) {
			$tries++;
			$code = ( '' !== $prefix ? $prefix . '-' : '' ) . strtoupper( wp_generate_password( 8, false, false ) );
			$r    = self::save( array_merge( $data, array( 'code' => $code, 'batch' => $batch, 'max_uses' => 1, 'per_user' => 1 ) ) );
			if ( is_wp_error( $r ) ) {
				if ( 'fw_mbr_coupon_taken' === $r->get_error_code() ) {
					continue;
				}
				return $r;
			}
			$codes[] = $code;
		}
		return $codes;
	}

	/**
	 * Remember a payment service's id for a coupon.
	 *
	 * @param int    $id
	 * @param string $key
	 * @param string $value
	 */
	public static function set_ref( $id, $key, $value ) {
		global $wpdb;
		$c = self::get( $id );
		if ( $c ) {
			$refs         = $c['gateway_refs'];
			$refs[ $key ] = (string) $value;
			$wpdb->update( FW_Membership_Installer::table( 'coupons' ), array( 'gateway_refs' => wp_json_encode( $refs ) ), array( 'id' => (int) $id ) );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Rules
	 * ---------------------------------------------------------------------- */

	/**
	 * Can this coupon be used for this plan / price / person now?
	 *
	 * @param array|null $c
	 * @param array      $plan
	 * @param array      $price
	 * @param int        $user_id
	 *
	 * @return true|WP_Error
	 */
	public static function validate( $c, array $plan, array $price, $user_id = 0 ) {
		$no = new WP_Error( 'fw_mbr_coupon', __( 'That code is not valid.', 'fw' ) );
		if ( ! $c || 'active' !== $c['status'] ) {
			return $no;
		}
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		if ( $c['starts_at'] && strtotime( $c['starts_at'] ) > $now ) {
			return $no;
		}
		if ( $c['expires_at'] && strtotime( $c['expires_at'] ) < $now ) {
			return new WP_Error( 'fw_mbr_coupon', __( 'That code has expired.', 'fw' ) );
		}
		if ( $c['plans'] && ! in_array( (int) $plan['id'], $c['plans'], true ) ) {
			return new WP_Error( 'fw_mbr_coupon', __( 'That code does not apply to this plan.', 'fw' ) );
		}
		if ( 'fixed' === $c['type'] && strtoupper( (string) $price['currency'] ) !== $c['currency'] && '' !== (string) $price['currency'] ) {
			return new WP_Error( 'fw_mbr_coupon', __( 'That code does not apply to this price.', 'fw' ) );
		}
		if ( $c['max_uses'] && $c['uses'] >= $c['max_uses'] ) {
			return new WP_Error( 'fw_mbr_coupon', __( 'That code has been used up.', 'fw' ) );
		}
		if ( $c['per_user'] && $user_id && self::used_by( $c['code'], $user_id ) >= $c['per_user'] ) {
			return new WP_Error( 'fw_mbr_coupon', __( 'You have already used that code.', 'fw' ) );
		}
		return true;
	}

	/**
	 * Completed checkouts by a person with a code.
	 *
	 * @param string $code
	 * @param int    $user_id
	 *
	 * @return int
	 */
	public static function used_by( $code, $user_id ) {
		$used = (array) get_user_meta( (int) $user_id, '_fw_mbr_coupon_used', false );
		return count( array_filter( $used, function ( $x ) use ( $code ) {
			return strtoupper( (string) $x ) === strtoupper( (string) $code );
		} ) );
	}

	/**
	 * The discount on an amount.
	 *
	 * @param array $c
	 * @param float $amount
	 *
	 * @return float
	 */
	public static function discount( array $c, $amount ) {
		$off = 'percent' === $c['type'] ? $amount * $c['amount'] / 100 : $c['amount'];
		return round( min( max( 0.0, $off ), max( 0.0, $amount ) ), 2 );
	}

	/**
	 * "20% off for 3 months".
	 *
	 * @param array $c
	 *
	 * @return string
	 */
	public static function label( array $c ) {
		$off = 'percent' === $c['type'] ? rtrim( rtrim( number_format( $c['amount'], 2, '.', '' ), '0' ), '.' ) . '%' : FW_Membership_Plans::money( $c['amount'], $c['currency'] );
		switch ( $c['duration'] ) {
			case 'forever':
				return sprintf( __( '%s off every payment', 'fw' ), $off );
			case 'repeating':
				return sprintf( _n( '%1$s off for %2$d month', '%1$s off for %2$d months', $c['months'], 'fw' ), $off, $c['months'] );
			default:
				return sprintf( __( '%s off the first payment', 'fw' ), $off );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Hooks
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _quote( $q, $plan, $price, $args ) {
		$code = self::clean_code( $args['coupon'] ?? '' );
		if ( '' === $code ) {
			return $q;
		}
		$c = self::get_by_code( $code );
		$v = self::validate( $c, $plan, $price, (int) ( $args['user_id'] ?? get_current_user_id() ) );
		if ( is_wp_error( $v ) ) {
			$q['errors'][] = $v->get_error_message();
			return $q;
		}
		$q['coupon']   = $c;
		$q['discount'] = self::discount( $c, $q['due'] );
		if ( $q['recurring'] && 'once' !== $c['duration'] ) {
			$q['discount_recurring'] = self::discount( $c, $q['amount'] );
		}
		return $q;
	}

	/**
	 * Stripe applies the discount itself (so renewals are discounted for the right
	 * number of months); the coupon is made there on first use.
	 *
	 * @internal
	 */
	public static function _stripe_session( $params, $payment, $membership, $price ) {
		$code = (string) ( $payment['coupon'] ?? '' );
		$c    = '' !== $code ? self::get_by_code( $code ) : null;
		$g    = FW_Membership_Gateways::get( 'stripe' );
		// A gift is sent to Stripe at its final, already-discounted price: no second discount.
		if ( ! $c || ! $g || ! empty( $payment['meta']['gift'] ) ) {
			return $params;
		}
		$mode = 'live' === $g->setting( 'mode' ) ? 'live' : 'test';
		$key  = 'stripe_' . $mode;
		$sid  = (string) ( $c['gateway_refs'][ $key ] ?? '' );
		if ( '' === $sid ) {
			$body = array( 'duration' => $c['duration'], 'name' => substr( $c['code'], 0, 40 ), 'metadata' => array( 'fw_mbr_coupon' => $c['id'] ) );
			if ( 'percent' === $c['type'] ) {
				$body['percent_off'] = $c['amount'];
			} else {
				$body['amount_off'] = FW_Membership_Payments::minor_units( $c['amount'], $c['currency'] );
				$body['currency']   = strtolower( $c['currency'] );
			}
			if ( 'repeating' === $c['duration'] ) {
				$body['duration_in_months'] = $c['months'];
			}
			$body['_idempotency'] = 'fw-mbr-coupon-' . $c['id'] . '-' . md5( wp_json_encode( $body ) );
			$r = $g->api( 'POST', 'coupons', $body );
			if ( is_wp_error( $r ) ) {
				// Never charge the full price for a payment recorded as discounted.
				$params['_fw_error'] = $r->get_error_message();
				return $params;
			}
			$sid = (string) $r['id'];
			self::set_ref( $c['id'], $key, $sid );
		}
		$params['discounts'] = array( array( 'coupon' => $sid ) );
		unset( $params['allow_promotion_codes'] );
		return $params;
	}

	/** @internal */
	public static function _completed( $q, $user_id, $membership_id, $payment_id ) {
		$code = is_array( $q['coupon'] ?? null ) ? (string) $q['coupon']['code'] : '';
		$c    = '' !== $code ? self::get_by_code( $code ) : null;
		if ( ! $c ) {
			return;
		}
		global $wpdb;
		$t = FW_Membership_Installer::table( 'coupons' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET uses = uses + 1 WHERE id = %d", $c['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		add_user_meta( (int) $user_id, '_fw_mbr_coupon_used', $c['code'] );
	}

	/* ---------------------------------------------------------------------- */

	public static function clean_code( $code ) {
		return strtoupper( substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $code ), 0, 100 ) );
	}

	private static function where( array $args ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( ! empty( $args['batch'] ) ) {
			$where[]  = 'batch = %s';
			$params[] = (string) $args['batch'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(code LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		return array( implode( ' AND ', $where ), $params );
	}

	private static function shape( array $row ) {
		foreach ( array( 'id', 'months', 'max_uses', 'per_user', 'uses' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$row['amount']       = (float) $row['amount'];
		$row['plans']        = array_map( 'intval', (array) json_decode( (string) $row['plans'], true ) );
		$row['gateway_refs'] = (array) json_decode( (string) $row['gateway_refs'], true );
		return $row;
	}
}
