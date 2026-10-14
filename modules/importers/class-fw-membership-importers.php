<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Importers (Membership → Import):
 *
 *   CSV        one row per membership: email (required), first_name, last_name, plan (slug,
 *              name or id), status, started_at, ends_at, renews_at, gateway (stripe /
 *              paypal), subscription_id, customer_id. Accounts are made for unknown emails
 *              (no email is sent unless asked). With a gateway and subscription_id the
 *              membership is linked to that subscription, so its renewals, failures and
 *              cancellation keep arriving and being applied. Re-running the same file
 *              changes nothing already imported (matched by user + plan + subscription).
 *   Roles      everyone with a WordPress role gets a plan (for sites whose members were a role).
 *   User meta  everyone whose user meta key holds a value gets that value's plan (for
 *              sites that kept a level or tier in a user field).
 *
 * Every run can be a dry run first, reporting what it would do.
 */
class FW_Membership_Importers {

	const COLUMNS = array( 'email', 'first_name', 'last_name', 'plan', 'status', 'started_at', 'ends_at', 'renews_at', 'gateway', 'subscription_id', 'customer_id' );

	/**
	 * Read a CSV file.
	 *
	 * @param string $path
	 * @param int    $limit 0 = all rows.
	 *
	 * @return array { header: string[], rows: array[] }|WP_Error
	 */
	public static function read_csv( $path, $limit = 0 ) {
		$fh = @fopen( $path, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return new WP_Error( 'fw_mbr_import', __( 'The file could not be read.', 'fw' ) );
		}
		$first = (string) fgets( $fh );
		$delim = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';
		$first = preg_replace( '/^\xEF\xBB\xBF/', '', $first ); // a byte-order mark
		$head  = array_map( function ( $h ) {
			return sanitize_key( str_replace( ' ', '_', strtolower( trim( $h ) ) ) );
		}, str_getcsv( trim( $first ), $delim ) );
		$rows  = array();
		while ( false !== ( $line = fgetcsv( $fh, 0, $delim ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			if ( array( null ) === $line || ! array_filter( $line, 'strlen' ) ) {
				continue;
			}
			$rows[] = array_combine( $head, array_pad( array_slice( $line, 0, count( $head ) ), count( $head ), '' ) );
			if ( $limit && count( $rows ) >= $limit ) {
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array( 'header' => $head, 'rows' => $rows );
	}

	/** @return array|null A plan by slug, name or id. */
	public static function find_plan( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return null;
		}
		if ( ctype_digit( $ref ) && FW_Membership_Plans::get( (int) $ref ) ) {
			return FW_Membership_Plans::get( (int) $ref );
		}
		$p = FW_Membership_Plans::get_by_slug( sanitize_title( $ref ) );
		if ( $p ) {
			return $p;
		}
		foreach ( FW_Membership_Plans::all() as $plan ) {
			if ( 0 === strcasecmp( $plan['name'], $ref ) ) {
				return $plan;
			}
		}
		return null;
	}

	private static function date( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return null;
		}
		$t = ctype_digit( $v ) ? (int) $v : strtotime( $v );
		return $t ? gmdate( 'Y-m-d H:i:s', $t ) : null;
	}

	/**
	 * Import rows.
	 *
	 * @param array[] $rows   column => value (after mapping)
	 * @param array   $opts   dry_run, create_users, notify (send set-password emails), default_plan
	 *
	 * @return array { created_users, imported, skipped, errors: string[] }
	 */
	public static function import_rows( array $rows, array $opts = array() ) {
		$dry = ! empty( $opts['dry_run'] );
		$out = array( 'created_users' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => array() );
		foreach ( $rows as $n => $r ) {
			$line  = $n + 2;
			$email = sanitize_email( (string) ( $r['email'] ?? '' ) );
			$plan  = self::find_plan( $r['plan'] ?? ( $opts['default_plan'] ?? '' ) );
			if ( ! is_email( $email ) ) {
				$out['errors'][] = sprintf( __( 'Line %d: no valid email.', 'fw' ), $line );
				continue;
			}
			if ( ! $plan ) {
				$out['errors'][] = sprintf( __( 'Line %1$d: no plan called "%2$s".', 'fw' ), $line, (string) ( $r['plan'] ?? '' ) );
				continue;
			}
			$u = get_user_by( 'email', $email );
			if ( ! $u && empty( $opts['create_users'] ) ) {
				$out['errors'][] = sprintf( __( 'Line %1$d: no account for %2$s.', 'fw' ), $line, $email );
				continue;
			}
			$sub     = sanitize_text_field( (string) ( $r['subscription_id'] ?? '' ) );
			$gateway = sanitize_key( (string) ( $r['gateway'] ?? '' ) );
			if ( $u ) {
				$existing = FW_Membership_Memberships::query( array( 'user_id' => $u->ID, 'plan_id' => $plan['id'], 'per_page' => 20 ) );
				$same     = array_filter( $existing, function ( $m ) use ( $sub ) {
					return 'import' === $m['source'] || ( '' !== $sub && $sub === $m['gateway_subscription_id'] ) || in_array( $m['status'], FW_Membership_Memberships::ACCESS_STATUSES, true );
				} );
				if ( $same ) {
					$out['skipped']++;
					continue;
				}
			}
			if ( $dry ) {
				$out['imported']++;
				$out['created_users'] += $u ? 0 : 1;
				continue;
			}
			if ( ! $u ) {
				$uid = wp_insert_user( array(
					'user_login' => class_exists( 'FW_Membership_Checkout' ) ? FW_Membership_Checkout::username( $email ) : sanitize_user( strstr( $email, '@', true ) . wp_rand( 100, 999 ), true ),
					'user_email' => $email,
					'user_pass'  => wp_generate_password( 24 ),
					'first_name' => sanitize_text_field( (string) ( $r['first_name'] ?? '' ) ),
					'last_name'  => sanitize_text_field( (string) ( $r['last_name'] ?? '' ) ),
					'role'       => get_option( 'default_role', 'subscriber' ),
				) );
				if ( is_wp_error( $uid ) ) {
					$out['errors'][] = sprintf( __( 'Line %1$d: %2$s', 'fw' ), $line, $uid->get_error_message() );
					continue;
				}
				if ( ! empty( $opts['notify'] ) ) {
					wp_new_user_notification( $uid, null, 'user' );
				}
				$u = get_userdata( $uid );
				$out['created_users']++;
			}
			$status = strtolower( trim( (string) ( $r['status'] ?? 'active' ) ) );
			$status = in_array( $status, FW_Membership_Memberships::STATUSES, true ) ? $status : 'active';
			$mid    = FW_Membership_Memberships::insert( array(
				'user_id'                 => $u->ID,
				'plan_id'                 => $plan['id'],
				'price_id'                => isset( $plan['prices'][0] ) && '' !== $sub ? (int) $plan['prices'][0]['id'] : 0,
				'status'                  => $status,
				'source'                  => 'import',
				'source_ref'              => 'line ' . $line,
				'gateway'                 => in_array( $gateway, array( 'stripe', 'paypal', 'offline' ), true ) ? $gateway : '',
				'gateway_subscription_id' => $sub,
				'started_at'              => self::date( $r['started_at'] ?? '' ) ?: current_time( 'mysql' ),
				'ends_at'                 => self::date( $r['ends_at'] ?? '' ),
				'renews_at'               => self::date( $r['renews_at'] ?? '' ),
			) );
			if ( ! $mid ) {
				$out['errors'][] = sprintf( __( 'Line %d: could not be saved.', 'fw' ), $line );
				continue;
			}
			if ( '' !== (string) ( $r['customer_id'] ?? '' ) && 'stripe' === $gateway ) {
				$g    = class_exists( 'FW_Membership_Gateways' ) ? FW_Membership_Gateways::get( 'stripe' ) : null;
				$mode = $g && 'live' === $g->setting( 'mode' ) ? 'live' : 'test'; // the mode Payment settings use now
				update_user_meta( $u->ID, '_fw_mbr_stripe_customer_' . $mode, sanitize_text_field( (string) $r['customer_id'] ) );
			}
			FW_Membership_Memberships::log( array( 'membership_id' => $mid, 'user_id' => $u->ID, 'plan_id' => $plan['id'], 'type' => 'imported', 'status_to' => $status, 'note' => __( 'Imported.', 'fw' ) ) );
			$out['imported']++;
		}
		FW_Membership_Service::flush_cache();
		return $out;
	}

	/**
	 * Give a plan to everyone with a role, or with a user meta value.
	 *
	 * @param string $by      role | meta
	 * @param array  $map     role (or meta value) => plan id
	 * @param string $meta_key For "meta".
	 * @param bool   $dry
	 *
	 * @return array { imported, skipped, errors }
	 */
	public static function import_from_users( $by, array $map, $meta_key = '', $dry = false ) {
		$rows = array();
		foreach ( $map as $key => $plan_id ) {
			$plan_id = (int) $plan_id;
			if ( ! $plan_id ) {
				continue;
			}
			$args = 'role' === $by ? array( 'role' => (string) $key ) : array( 'meta_key' => sanitize_key( $meta_key ), 'meta_value' => (string) $key ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( get_users( array_merge( $args, array( 'fields' => array( 'user_email' ), 'number' => 5000 ) ) ) as $u ) {
				$rows[] = array( 'email' => $u->user_email, 'plan' => (string) $plan_id, 'status' => 'active' );
			}
		}
		return self::import_rows( $rows, array( 'dry_run' => $dry ) );
	}

	/** @return string[] Distinct values of a user meta key (up to 50, for mapping). */
	public static function meta_values( $key ) {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 50", sanitize_key( $key ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
