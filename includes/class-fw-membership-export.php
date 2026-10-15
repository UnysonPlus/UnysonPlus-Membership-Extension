<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Export memberships to CSV (Members → Export CSV), one row per membership.
 *
 * The first columns are exactly the ones the importer reads (email, first_name, last_name,
 * plan, status, started_at, ends_at, renews_at, gateway, subscription_id, customer_id), so a
 * file exported from one site imports into another unchanged, subscriptions included.
 * Columns after those are for reading: ids, names, the source, trial and cancel dates.
 *
 * The list's filters apply (status, plan, search). Text cells that start with = + - @ get a
 * leading apostrophe so a spreadsheet does not run them as formulas.
 */
class FW_Membership_Export {

	const EXTRA = array( 'membership_id', 'user_id', 'display_name', 'plan_name', 'source', 'trial_ends_at', 'cancelled_at', 'created_at' );

	public static function init() {
		add_filter( 'unysonplus_membership_admin_action', array( __CLASS__, '_action' ), 10, 2 );
	}

	/** @return string[] The CSV header. */
	public static function columns() {
		return array_merge( self::import_columns(), self::EXTRA );
	}

	/** @return string[] The importer's columns (kept here too, so export works with Importers off). */
	private static function import_columns() {
		return class_exists( 'FW_Membership_Importers' ) ? FW_Membership_Importers::COLUMNS : array( 'email', 'first_name', 'last_name', 'plan', 'status', 'started_at', 'ends_at', 'renews_at', 'gateway', 'subscription_id', 'customer_id' );
	}

	/**
	 * One CSV row for a membership.
	 *
	 * @param array $m A membership row.
	 *
	 * @return array column => value
	 */
	public static function row( array $m ) {
		$u    = get_userdata( (int) $m['user_id'] );
		$plan = FW_Membership_Plans::get( (int) $m['plan_id'] );
		$cust = '';
		if ( $u && 'stripe' === $m['gateway'] ) {
			$g    = class_exists( 'FW_Membership_Gateways' ) ? FW_Membership_Gateways::get( 'stripe' ) : null;
			$mode = $g && 'live' === $g->setting( 'mode' ) ? 'live' : 'test';
			$cust = (string) get_user_meta( $u->ID, '_fw_mbr_stripe_customer_' . $mode, true );
		}
		$row = array(
			'email'           => $u ? $u->user_email : '',
			'first_name'      => $u ? $u->first_name : '',
			'last_name'       => $u ? $u->last_name : '',
			'plan'            => $plan ? $plan['slug'] : '',
			'status'          => (string) $m['status'],
			'started_at'      => (string) $m['started_at'],
			'ends_at'         => (string) $m['ends_at'],
			'renews_at'       => (string) $m['renews_at'],
			'gateway'         => (string) $m['gateway'],
			'subscription_id' => (string) $m['gateway_subscription_id'],
			'customer_id'     => $cust,
			'membership_id'   => (int) $m['id'],
			'user_id'         => (int) $m['user_id'],
			'display_name'    => $u ? $u->display_name : '',
			'plan_name'       => $plan ? $plan['name'] : '',
			'source'          => (string) $m['source'],
			'trial_ends_at'   => (string) ( $m['trial_ends_at'] ?? '' ),
			'cancelled_at'    => (string) ( $m['cancelled_at'] ?? '' ),
			'created_at'      => (string) ( $m['created_at'] ?? '' ),
		);
		/** Change or add export columns (add the same keys to unysonplus_membership_export_columns). */
		$row = (array) apply_filters( 'unysonplus_membership_export_row', $row, $m );
		$out = array();
		foreach ( self::header() as $col ) {
			$out[ $col ] = self::cell( $row[ $col ] ?? '' );
		}
		return $out;
	}

	/** @return string[] */
	private static function header() {
		return (array) apply_filters( 'unysonplus_membership_export_columns', self::columns() );
	}

	/** Keep a spreadsheet from running a cell as a formula. */
	private static function cell( $v ) {
		$v = is_scalar( $v ) ? (string) $v : '';
		return '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $v ) ? "'" . $v : $v;
	}

	/**
	 * Write the CSV.
	 *
	 * @param resource $fh
	 * @param array    $args Memberships query args (plan_id, status, search).
	 *
	 * @return int Rows written.
	 */
	public static function write( $fh, array $args = array() ) {
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- so spreadsheets read accents right
		fputcsv( $fh, self::header() );
		$n    = 0;
		$page = 1;
		do {
			$rows = FW_Membership_Memberships::query( array_merge( $args, array( 'per_page' => 500, 'page' => $page ) ) );
			foreach ( $rows as $m ) {
				fputcsv( $fh, array_values( self::row( $m ) ) );
				$n++;
			}
			$page++;
		} while ( count( $rows ) === 500 );
		return $n;
	}

	/** @internal Members → Export CSV. */
	public static function _action( $back, $action ) {
		if ( 'export_members' !== $action ) {
			return $back;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page before actions run
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$args   = array(
			'plan_id' => isset( $_GET['plan_id'] ) ? (int) $_GET['plan_id'] : 0,
			'status'  => '' === $status ? '' : ( 'access' === $status ? 'access' : array( $status ) ),
			'search'  => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
		);
		// phpcs:enable
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="members-' . current_time( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		self::write( $out, $args );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
