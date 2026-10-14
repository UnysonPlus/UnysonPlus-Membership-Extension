<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Cohorts and content.
 *
 *   by month    people who joined in a month, and what share still had access 1, 2, 3…
 *               months later
 *   by source   the share still with access, by how they came (paid, free sign-up, by
 *               hand, gift, team seat, store order…)
 *   content     which protected items members open, counted per item per month with no
 *               record of who (table fw_mbr_content_views); counted only when a member with
 *               a plan opens an item a rule protects
 */
class FW_Membership_Cohorts {

	const VERSION = '1.0.0';

	public static function init() {
		FW_Membership_Installer::module_tables( 'analytics', self::VERSION, array( __CLASS__, 'schema' ), array( 'content_views' ) );
		add_action( 'template_redirect', array( __CLASS__, '_count' ), 50 );
	}

	/** @internal */
	public static function schema( $collate ) {
		$t = FW_Membership_Installer::table( 'content_views' );
		return array( "CREATE TABLE {$t} (
	post_id bigint(20) unsigned NOT NULL default 0,
	period char(7) NOT NULL default '',
	views int(11) unsigned NOT NULL default 0,
	PRIMARY KEY  (post_id,period)
) {$collate};" );
	}

	/** @internal Count a member opening a protected item. */
	public static function _count() {
		if ( ! is_singular() || is_preview() ) {
			return;
		}
		$persona = FW_Membership_Access::persona();
		$post    = get_queried_object();
		if ( ! $post instanceof WP_Post || '' !== (string) $persona['preview'] || ! $persona['plans'] || current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		if ( ! FW_Membership_Access::is_protected( $post ) || ! FW_Membership_Access::can( $post ) ) {
			return;
		}
		global $wpdb;
		$t = FW_Membership_Installer::table( 'content_views' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (post_id, period, views) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1", $post->ID, current_time( 'Y-m' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Retention by joining month.
	 *
	 * @param int $n Cohorts (months back).
	 *
	 * @return array ym => { size, retained: [ months_later => % ] }
	 */
	public static function by_month( $n = 12 ) {
		global $wpdb;
		$m    = FW_Membership_Installer::table( 'memberships' );
		$from = FW_Membership_Reports::months( $n )[0] . '-01 00:00:00';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, MIN(started_at) AS joined FROM {$m} WHERE started_at IS NOT NULL AND status <> 'pending' AND source <> 'group_seat' GROUP BY user_id HAVING joined >= %s", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$now  = current_time( 'mysql' );
		$out  = array();
		foreach ( FW_Membership_Reports::months( $n ) as $ym ) {
			$out[ $ym ] = array( 'size' => 0, 'retained' => array() );
		}
		$people = array();
		foreach ( $rows as $r ) {
			$ym = substr( $r['joined'], 0, 7 );
			if ( isset( $out[ $ym ] ) ) {
				$out[ $ym ]['size']++;
				$people[ $ym ][] = (int) $r['user_id'];
			}
		}
		foreach ( $people as $ym => $users ) {
			$months_since = (int) floor( ( strtotime( $now ) - strtotime( $ym . '-01' ) ) / ( 30.4375 * DAY_IN_SECONDS ) );
			for ( $k = 1; $k <= min( 11, $months_since ); $k++ ) {
				$at   = gmdate( 'Y-m-d H:i:s', strtotime( $ym . "-01 +{$k} months" ) );
				$left = 0;
				foreach ( $users as $uid ) {
					$still = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE user_id = %d AND started_at <= %s AND ( ends_at IS NULL OR ends_at > %s ) AND status NOT IN ('pending','refunded')", $uid, $at, $at ) ); // phpcs:ignore WordPress.DB.PreparedSQL
					$left += $still ? 1 : 0;
				}
				$out[ $ym ]['retained'][ $k ] = round( 100 * $left / count( $users ) );
			}
		}
		return $out;
	}

	/** @return array source => { total, with_access, rate (%) } */
	public static function by_source() {
		global $wpdb;
		$m    = FW_Membership_Installer::table( 'memberships' );
		$in   = "'" . implode( "','", FW_Membership_Memberships::ACCESS_STATUSES ) . "'";
		$rows = (array) $wpdb->get_results( "SELECT source, COUNT(*) AS total, SUM( status IN ({$in}) ) AS live FROM {$m} WHERE status <> 'pending' GROUP BY source ORDER BY total DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ $r['source'] ] = array( 'total' => (int) $r['total'], 'with_access' => (int) $r['live'], 'rate' => $r['total'] ? round( 100 * (int) $r['live'] / (int) $r['total'] ) : 0 );
		}
		return $out;
	}

	/** @return array[] The most-read protected items: { post_id, title, views } over the last N months. */
	public static function top_content( $months = 3, $limit = 15 ) {
		global $wpdb;
		$t    = FW_Membership_Installer::table( 'content_views' );
		$from = FW_Membership_Reports::months( $months )[0];
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, SUM(views) AS views FROM {$t} WHERE period >= %s GROUP BY post_id ORDER BY views DESC LIMIT %d", $from, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( function ( $r ) {
			return array( 'post_id' => (int) $r['post_id'], 'title' => get_the_title( (int) $r['post_id'] ), 'views' => (int) $r['views'] );
		}, $rows );
	}
}
