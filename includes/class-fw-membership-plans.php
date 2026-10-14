<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Plans and their prices. Repository: the only SQL for these two tables; no hooks.
 *
 * A plan row is returned as an array with its prices under `prices`, so callers
 * never assemble the two themselves.
 */
class FW_Membership_Plans {

	/** How long a membership of the plan lasts, when it does not renew. */
	const DURATION_UNITS = array( 'day', 'week', 'month', 'year' );

	/** How a price is charged. `free` and `once` never renew. */
	const BILLING = array( 'free', 'once', 'day', 'week', 'month', 'year', 'lifetime' );

	/** @var array|null Request cache of every plan, by id. */
	private static $cache = null;

	/** @var int Goes up whenever plans change, so caches built on plans know to rebuild. */
	public static $generation = 0;

	public static function flush() {
		self::$cache = null;
		self::$generation++;
	}

	/**
	 * Every plan, ordered by priority (highest first) then name.
	 *
	 * @param string $status '' for all, or active|archived.
	 *
	 * @return array[]
	 */
	public static function all( $status = '' ) {
		if ( null === self::$cache ) {
			global $wpdb;
			$plans  = FW_Membership_Installer::table( 'plans' );
			$prices = FW_Membership_Installer::table( 'prices' );

			self::$cache = array();
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$plans} ORDER BY priority DESC, name ASC", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL
				self::$cache[ (int) $row['id'] ] = self::shape( $row );
			}
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$prices} WHERE status = 'active' ORDER BY sort_order ASC, id ASC", ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$pid = (int) $p['plan_id'];
				if ( isset( self::$cache[ $pid ] ) ) {
					self::$cache[ $pid ]['prices'][] = self::shape_price( $p );
				}
			}
		}

		if ( '' === $status ) {
			return self::$cache;
		}

		return array_filter( self::$cache, function ( $plan ) use ( $status ) {
			return $plan['status'] === $status;
		} );
	}

	/**
	 * @param int $id
	 *
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();

		return $all[ (int) $id ] ?? null;
	}

	/**
	 * Is this a single-item plan (Pay per item: one post, page or file, sold on its own)?
	 * Item plans are left out of pricing tables, plan switching and the public plan list.
	 *
	 * @param array|null $plan
	 *
	 * @return bool
	 */
	public static function is_item( $plan ) {
		return is_array( $plan ) && ! empty( $plan['settings']['item_type'] );
	}

	/**
	 * Any price by id, retired ones included (a member may still pay with one).
	 *
	 * @param int $id
	 *
	 * @return array|null
	 */
	public static function get_price( $id ) {
		$id = (int) $id;
		if ( ! $id ) {
			return null;
		}
		foreach ( self::all() as $plan ) {
			foreach ( $plan['prices'] as $p ) {
				if ( $p['id'] === $id ) {
					return $p;
				}
			}
		}
		global $wpdb;
		$t   = FW_Membership_Installer::table( 'prices' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::shape_price( $row ) : null;
	}

	/**
	 * Remember a payment service's id for a price (its product / price object).
	 *
	 * @param int    $price_id
	 * @param string $key
	 * @param string $value
	 */
	public static function set_price_ref( $price_id, $key, $value ) {
		global $wpdb;
		$p = self::get_price( $price_id );
		if ( ! $p ) {
			return;
		}
		$refs         = $p['gateway_refs'];
		$refs[ $key ] = (string) $value;
		$wpdb->update( FW_Membership_Installer::table( 'prices' ), array( 'gateway_refs' => wp_json_encode( $refs ) ), array( 'id' => (int) $price_id ) );
		self::flush();
	}

	/**
	 * @param string $slug
	 *
	 * @return array|null
	 */
	public static function get_by_slug( $slug ) {
		foreach ( self::all() as $plan ) {
			if ( $plan['slug'] === $slug ) {
				return $plan;
			}
		}
		return null;
	}

	/**
	 * Every plan this plan includes: itself, its parent, its parent's parent…
	 * (inheritance: Gold includes what Silver unlocks). Guarded against loops.
	 *
	 * @param int $id
	 *
	 * @return int[]
	 */
	public static function lineage( $id ) {
		$out  = array();
		$id   = (int) $id;
		while ( $id && ! in_array( $id, $out, true ) && ( $plan = self::get( $id ) ) ) {
			$out[] = $id;
			$id    = (int) $plan['parent_id'];
		}
		return $out;
	}

	/**
	 * Insert or update a plan and replace its prices.
	 *
	 * @param array $data  name, slug, description, status, priority, parent_id,
	 *                     duration_value, duration_unit, fixed_end_date, signup_limit, settings
	 * @param array $prices [ [ label, amount, currency, billing, interval_count, trial_days, setup_fee ], … ]
	 * @param int   $id    0 to insert.
	 *
	 * @return int|WP_Error The plan id.
	 */
	public static function save( array $data, array $prices = array(), $id = 0 ) {
		global $wpdb;

		$id   = (int) $id;
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'fw_mbr_plan_name', __( 'A plan needs a name.', 'fw' ) );
		}

		$slug = sanitize_title( (string) ( $data['slug'] ?? '' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}
		$taken = self::get_by_slug( $slug );
		if ( $taken && (int) $taken['id'] !== $id ) {
			return new WP_Error( 'fw_mbr_plan_slug', sprintf( __( 'Another plan already uses the slug "%s".', 'fw' ), $slug ) );
		}

		$parent = (int) ( $data['parent_id'] ?? 0 );
		if ( $id && $parent && in_array( $id, self::lineage( $parent ), true ) ) {
			return new WP_Error( 'fw_mbr_plan_loop', __( 'A plan cannot include itself, directly or through another plan.', 'fw' ) );
		}

		$unit   = (string) ( $data['duration_unit'] ?? '' );
		$status = (string) ( $data['status'] ?? 'active' );
		$row    = array(
			'slug'           => $slug,
			'name'           => $name,
			'description'    => (string) ( $data['description'] ?? '' ),
			'status'         => in_array( $status, array( 'active', 'archived' ), true ) ? $status : 'active',
			'priority'       => (int) ( $data['priority'] ?? 0 ),
			'parent_id'      => $parent,
			'duration_value' => in_array( $unit, self::DURATION_UNITS, true ) ? max( 0, (int) ( $data['duration_value'] ?? 0 ) ) : 0,
			'duration_unit'  => in_array( $unit, self::DURATION_UNITS, true ) ? $unit : '',
			'fixed_end_date' => ! empty( $data['fixed_end_date'] ) ? gmdate( 'Y-m-d 23:59:59', strtotime( (string) $data['fixed_end_date'] ) ) : null,
			'signup_limit'   => max( 0, (int) ( $data['signup_limit'] ?? 0 ) ),
			'settings'       => wp_json_encode( (array) ( $data['settings'] ?? array() ) ),
			'updated_at'     => current_time( 'mysql' ),
		);

		$table = FW_Membership_Installer::table( 'plans' );
		if ( $id ) {
			$ok = $wpdb->update( $table, $row, array( 'id' => $id ) );
		} else {
			$row['created_at'] = $row['updated_at'];
			$ok                = $wpdb->insert( $table, $row );
			$id                = (int) $wpdb->insert_id;
		}
		if ( false === $ok ) {
			return new WP_Error( 'fw_mbr_db', __( 'The plan could not be saved.', 'fw' ) );
		}

		self::replace_prices( $id, $prices );
		self::flush();

		return $id;
	}

	/**
	 * Prices are versioned by retiring, not editing: a price a member pays with must
	 * keep meaning what it meant. Unchanged rows (matched by id) are kept; changed
	 * or removed rows are archived; new rows are inserted.
	 *
	 * @param int   $plan_id
	 * @param array $prices
	 */
	private static function replace_prices( $plan_id, array $prices ) {
		global $wpdb;
		$table   = FW_Membership_Installer::table( 'prices' );
		$current = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE plan_id = %d AND status = 'active'", $plan_id ), ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$current[ (int) $p['id'] ] = $p;
		}

		$keep = array();
		foreach ( array_values( $prices ) as $i => $p ) {
			$billing = in_array( $p['billing'] ?? '', self::BILLING, true ) ? $p['billing'] : 'once';
			$row     = array(
				'plan_id'        => (int) $plan_id,
				'label'          => sanitize_text_field( (string) ( $p['label'] ?? '' ) ),
				'amount'         => 'free' === $billing ? 0 : round( max( 0, (float) ( $p['amount'] ?? 0 ) ), 2 ),
				'currency'       => strtoupper( substr( preg_replace( '/[^a-z]/i', '', (string) ( $p['currency'] ?? '' ) ), 0, 3 ) ),
				'billing'        => $billing,
				'interval_count' => max( 1, (int) ( $p['interval_count'] ?? 1 ) ),
				'trial_days'     => max( 0, (int) ( $p['trial_days'] ?? 0 ) ),
				'setup_fee'      => round( max( 0, (float) ( $p['setup_fee'] ?? 0 ) ), 2 ),
				'sort_order'     => $i,
			);
			$pid = (int) ( $p['id'] ?? 0 );

			if ( $pid && isset( $current[ $pid ] ) && self::same_price( $current[ $pid ], $row ) ) {
				$wpdb->update( $table, array( 'sort_order' => $i, 'label' => $row['label'] ), array( 'id' => $pid ) );
				$keep[] = $pid;
				continue;
			}
			$row['status']     = 'active';
			$row['created_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $row );
		}

		foreach ( array_diff( array_keys( $current ), $keep ) as $retire ) {
			$wpdb->update( $table, array( 'status' => 'archived' ), array( 'id' => (int) $retire ) );
		}
	}

	private static function same_price( array $old, array $new ) {
		foreach ( array( 'amount', 'currency', 'billing', 'interval_count', 'trial_days', 'setup_fee' ) as $k ) {
			if ( (string) $old[ $k ] !== (string) $new[ $k ] && (float) $old[ $k ] !== (float) $new[ $k ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Delete a plan that nobody holds. Plans with memberships are archived instead,
	 * so history and payments keep their meaning.
	 *
	 * @param int $id
	 *
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( FW_Membership_Memberships::count( array( 'plan_id' => $id ) ) ) {
			return new WP_Error( 'fw_mbr_plan_in_use', __( 'This plan has members, so it was archived instead of deleted.', 'fw' ) );
		}
		$wpdb->delete( FW_Membership_Installer::table( 'prices' ), array( 'plan_id' => $id ) );
		$wpdb->delete( FW_Membership_Installer::table( 'plans' ), array( 'id' => $id ) );
		$wpdb->update( FW_Membership_Installer::table( 'plans' ), array( 'parent_id' => 0 ), array( 'parent_id' => $id ) );
		self::flush();

		return true;
	}

	/**
	 * @param int $id
	 */
	public static function archive( $id ) {
		global $wpdb;
		$wpdb->update( FW_Membership_Installer::table( 'plans' ), array( 'status' => 'archived', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );
		self::flush();
	}

	/* ---------------------------------------------------------------------- */

	private static function shape( array $row ) {
		$row['id']             = (int) $row['id'];
		$row['priority']       = (int) $row['priority'];
		$row['parent_id']      = (int) $row['parent_id'];
		$row['duration_value'] = (int) $row['duration_value'];
		$row['signup_limit']   = (int) $row['signup_limit'];
		$row['settings']       = (array) json_decode( (string) $row['settings'], true );
		$row['prices']         = array();
		return $row;
	}

	private static function shape_price( array $p ) {
		$p['id']             = (int) $p['id'];
		$p['plan_id']        = (int) $p['plan_id'];
		$p['amount']         = (float) $p['amount'];
		$p['setup_fee']      = (float) $p['setup_fee'];
		$p['interval_count'] = (int) $p['interval_count'];
		$p['trial_days']     = (int) $p['trial_days'];
		$p['gateway_refs']   = (array) json_decode( (string) ( $p['gateway_refs'] ?? '' ), true );
		return $p;
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
		return trim( strtoupper( (string) $currency ) . ' ' . number_format_i18n( (float) $amount, 2 ) );
	}

	/**
	 * "Free", "$9.00 / month", "$90.00 every 2 years", "$199.00 once".
	 *
	 * @param array $price
	 *
	 * @return string
	 */
	public static function price_label( array $price ) {
		if ( 'free' === $price['billing'] || ( ! $price['amount'] && in_array( $price['billing'], array( 'once', 'lifetime' ), true ) ) ) {
			return __( 'Free', 'fw' );
		}
		$money = trim( $price['currency'] . ' ' . number_format_i18n( $price['amount'], 2 ) );
		$units = array(
			'day'   => array( __( 'day', 'fw' ), __( 'days', 'fw' ) ),
			'week'  => array( __( 'week', 'fw' ), __( 'weeks', 'fw' ) ),
			'month' => array( __( 'month', 'fw' ), __( 'months', 'fw' ) ),
			'year'  => array( __( 'year', 'fw' ), __( 'years', 'fw' ) ),
		);
		if ( isset( $units[ $price['billing'] ] ) ) {
			$n = (int) $price['interval_count'];
			return $n > 1
				? sprintf( __( '%1$s every %2$d %3$s', 'fw' ), $money, $n, $units[ $price['billing'] ][1] )
				: sprintf( __( '%1$s / %2$s', 'fw' ), $money, $units[ $price['billing'] ][0] );
		}
		return 'lifetime' === $price['billing']
			? sprintf( __( '%s for life', 'fw' ), $money )
			: sprintf( __( '%s once', 'fw' ), $money );
	}

	/**
	 * "Lasts 1 year", "Until 31 Dec 2026", "No end date".
	 *
	 * @param array $plan
	 *
	 * @return string
	 */
	public static function duration_label( array $plan ) {
		if ( ! empty( $plan['fixed_end_date'] ) ) {
			return sprintf( __( 'Until %s', 'fw' ), date_i18n( get_option( 'date_format' ), strtotime( $plan['fixed_end_date'] ) ) );
		}
		if ( $plan['duration_value'] && $plan['duration_unit'] ) {
			return sprintf( __( 'Lasts %1$d %2$s', 'fw' ), $plan['duration_value'], $plan['duration_unit'] . ( $plan['duration_value'] > 1 ? 's' : '' ) );
		}
		return __( 'No end date', 'fw' );
	}
}
