<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * One person, one contact. A member's contact is the one linked to their user; if
 * there is none, the contact with their email (made if missing) is linked. A contact
 * already linked to someone else is never taken over. Name and email flow from the
 * WordPress account to the contact, never back.
 */
class FW_Membership_CRM_Identity {

	public static function init() {
		add_action( 'profile_update', array( __CLASS__, '_profile_update' ), 20, 2 );
	}

	/**
	 * The member's contact id, made and linked when needed.
	 *
	 * @param int  $user_id
	 * @param bool $create
	 *
	 * @return int 0 when there is none (or it could not be linked).
	 */
	public static function contact_id( $user_id, $create = true ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! FW_Membership_CRM::available() ) {
			return 0;
		}
		$cid = (int) FW_CRM_Contacts::id_for_user( $user_id );
		if ( $cid || ! $create || ! FW_Membership_CRM::settings()['contacts'] ) {
			return $cid;
		}
		$u = get_userdata( $user_id );
		if ( ! $u || ! is_email( $u->user_email ) ) {
			return 0;
		}
		// create() returns the existing contact with this email, or a new one.
		$cid = FW_CRM_Contacts::create( array(
			'email'      => $u->user_email,
			'first_name' => (string) $u->first_name,
			'last_name'  => (string) $u->last_name,
			'source'     => FW_Membership_CRM::SOURCE,
		) );
		if ( is_wp_error( $cid ) || ! $cid ) {
			return 0;
		}
		$linked = FW_CRM_Contacts::link_user( (int) $cid, $user_id );
		if ( is_wp_error( $linked ) ) {
			// The contact with this email belongs to another user: leave it alone.
			return 0;
		}
		return (int) $cid;
	}

	/** @internal Name and email follow the WordPress account. */
	public static function _profile_update( $user_id, $old ) {
		$cid = self::contact_id( $user_id, false );
		$u   = get_userdata( $user_id );
		if ( ! $cid || ! $u || ! $old instanceof WP_User ) {
			return;
		}
		$changes = array();
		if ( strtolower( $u->user_email ) !== strtolower( $old->user_email ) ) {
			$changes['email'] = $u->user_email;
		}
		foreach ( array( 'first_name', 'last_name' ) as $k ) {
			if ( (string) $u->$k !== (string) get_user_meta( $user_id, '_fw_mbr_crm_' . $k, true ) ) {
				$changes[ $k ] = (string) $u->$k;
				update_user_meta( $user_id, '_fw_mbr_crm_' . $k, (string) $u->$k );
			}
		}
		if ( $changes ) {
			FW_CRM_Contacts::update( $cid, $changes ); // an email another contact uses is refused there
		}
	}
}
