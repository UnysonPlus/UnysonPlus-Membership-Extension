<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Plans mirrored to tags. Each plan has a managed tag (prefix + plan slug, e.g.
 * member-gold), and optionally "member" (has access now) and "former-member" (had a
 * membership, has none now). Tags are managed by Membership in the CRM (locked on the
 * contact page), added and removed one by one (never replacing a contact's other
 * tags), and recomputed from the memberships after every change, so they cannot drift.
 */
class FW_Membership_CRM_Sync {

	const MEMBER        = 'member';
	const FORMER_MEMBER = 'former-member';

	public static function init() {
		add_action( 'unysonplus_membership_changed', array( __CLASS__, '_changed' ), 20, 3 );
		add_action( 'unysonplus_crm_contacts_merged', array( __CLASS__, '_merged' ), 20, 2 );
	}

	/** @internal */
	public static function _changed( $event, $m, $ctx ) {
		self::sync_user( (int) $m['user_id'] );
	}

	/** @internal Two contacts became one: put the kept one in step. */
	public static function _merged( $kept, $removed ) {
		$c = FW_CRM_Contacts::get( is_array( $kept ) ? (int) $kept['id'] : (int) $kept );
		if ( $c && ! empty( $c['user_id'] ) ) {
			self::sync_user( (int) $c['user_id'] );
		}
	}

	/**
	 * @param array $plan
	 *
	 * @return string The plan's tag slug.
	 */
	public static function plan_tag( array $plan ) {
		return sanitize_key( FW_Membership_CRM::settings()['tag_prefix'] . $plan['slug'] );
	}

	/**
	 * Bring one member's contact in step with their memberships.
	 *
	 * @param int $user_id
	 *
	 * @return int The contact id (0 when there is none).
	 */
	public static function sync_user( $user_id ) {
		$s   = FW_Membership_CRM::settings();
		$all = FW_Membership_Memberships::query( array( 'user_id' => (int) $user_id, 'per_page' => 200 ) );
		$cid = FW_Membership_CRM_Identity::contact_id( $user_id, (bool) $all );
		if ( ! $cid ) {
			return 0;
		}
		if ( $s['tags'] || $s['status_tags'] ) {
			$want = array();
			$has  = false;
			foreach ( $all as $m ) {
				if ( in_array( $m['status'], FW_Membership_Memberships::ACCESS_STATUSES, true ) ) {
					$has  = true;
					$plan = FW_Membership_Plans::get( $m['plan_id'] );
					if ( $plan && $s['tags'] ) {
						$want[ self::plan_tag( $plan ) ] = sprintf( __( 'Member: %s', 'fw' ), $plan['name'] );
					}
				}
			}
			if ( $s['status_tags'] ) {
				if ( $has ) {
					$want[ self::MEMBER ] = __( 'Member', 'fw' );
				} elseif ( $all ) {
					$want[ self::FORMER_MEMBER ] = __( 'Former member', 'fw' );
				}
			}
			// Claim each tag as managed (makes it if missing), then add what is wanted…
			foreach ( $want as $slug => $title ) {
				FW_CRM_Lists::get_or_create( $slug, 'tag', array( 'title' => $title, 'managed_by' => FW_Membership_CRM::SOURCE ) );
			}
			if ( $want ) {
				FW_CRM_Tags::add( $cid, $want, FW_Membership_CRM::SOURCE );
			}
			// …and take off the managed ones that are no longer true.
			$drop = array_diff( self::managed_slugs(), array_keys( $want ) );
			if ( $drop ) {
				FW_CRM_Tags::remove( $cid, array_values( $drop ), FW_Membership_CRM::SOURCE );
			}
		}
		if ( $s['fields'] ) {
			FW_Membership_CRM_Summary::update_contact( $cid, $user_id, $all );
		}
		return $cid;
	}

	/** @return string[] The tags Membership manages (every plan's, and the status ones). */
	public static function managed_slugs() {
		$out = array( self::MEMBER, self::FORMER_MEMBER );
		foreach ( FW_Membership_Plans::all() as $plan ) {
			$out[] = self::plan_tag( $plan );
		}
		foreach ( FW_CRM_Lists::all( 'tag' ) as $t ) {
			if ( FW_Membership_CRM::SOURCE === (string) ( $t->managed_by ?? '' ) ) {
				$out[] = (string) $t->slug;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Every member, in batches (the "bring up to date" button).
	 *
	 * @return int How many people were synced.
	 */
	public static function sync_all() {
		global $wpdb;
		$t     = FW_Membership_Installer::table( 'memberships' );
		$users = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$t} WHERE user_id > 0" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$n     = 0;
		foreach ( $users as $uid ) {
			if ( get_userdata( $uid ) && self::sync_user( $uid ) ) {
				$n++;
			}
		}
		return $n;
	}
}
