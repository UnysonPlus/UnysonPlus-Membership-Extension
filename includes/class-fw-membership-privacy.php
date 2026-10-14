<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Memberships in WordPress's Export / Erase Personal Data tools (Tools → Export
 * Personal Data / Erase Personal Data), keyed by the account's email.
 *
 *  - Export: every membership (plan, status, dates, source) and its history, and the
 *    Membership state of the account (email confirmed, approval).
 *  - Erase: memberships and history are deleted; payment records, which a business
 *    usually must keep, are anonymised in place (the link to the person and any
 *    personal details in their notes are removed, the amounts stay). The person's
 *    WordPress account itself is WordPress's to erase, not ours.
 */
class FW_Membership_Privacy {

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, '_register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, '_register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, '_policy_text' ) );
	}

	/** @internal */
	public static function _register_exporter( $exporters ) {
		$exporters['unysonplus-membership'] = array(
			'exporter_friendly_name' => __( 'Memberships', 'fw' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/** @internal */
	public static function _register_eraser( $erasers ) {
		$erasers['unysonplus-membership'] = array(
			'eraser_friendly_name' => __( 'Memberships', 'fw' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @param string $email
	 * @param int    $page
	 *
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user ) {
			foreach ( FW_Membership_Memberships::query( array( 'user_id' => $user->ID, 'per_page' => 500 ) ) as $m ) {
				$plan   = FW_Membership_Plans::get( $m['plan_id'] );
				$data[] = array(
					'group_id'    => 'unysonplus-memberships',
					'group_label' => __( 'Memberships', 'fw' ),
					'item_id'     => 'membership-' . $m['id'],
					'data'        => array(
						array( 'name' => __( 'Plan', 'fw' ), 'value' => $plan ? $plan['name'] : (string) $m['plan_id'] ),
						array( 'name' => __( 'Status', 'fw' ), 'value' => $m['status'] ),
						array( 'name' => __( 'Started', 'fw' ), 'value' => (string) $m['started_at'] ),
						array( 'name' => __( 'Ends', 'fw' ), 'value' => (string) $m['ends_at'] ),
						array( 'name' => __( 'Source', 'fw' ), 'value' => $m['source'] ),
					),
				);
			}
			foreach ( FW_Membership_Memberships::history( array( 'user_id' => $user->ID, 'limit' => 500 ) ) as $h ) {
				$data[] = array(
					'group_id'    => 'unysonplus-membership-history',
					'group_label' => __( 'Membership history', 'fw' ),
					'item_id'     => 'membership-event-' . $h->id,
					'data'        => array(
						array( 'name' => __( 'What happened', 'fw' ), 'value' => $h->type ),
						array( 'name' => __( 'When', 'fw' ), 'value' => (string) $h->created_at ),
						array( 'name' => __( 'Note', 'fw' ), 'value' => (string) $h->note ),
					),
				);
			}
			$profile = array();
			foreach ( FW_Membership_Profile::fields() as $key => $f ) {
				$v = FW_Membership_Profile::get( $user->ID, $key );
				if ( '' !== $v ) {
					$profile[] = array( 'name' => $f['label'], 'value' => $v );
				}
			}
			if ( $profile ) {
				$data[] = array(
					'group_id'    => 'unysonplus-membership-profile',
					'group_label' => __( 'Member profile', 'fw' ),
					'item_id'     => 'membership-profile-' . $user->ID,
					'data'        => $profile,
				);
			}
			$verified = (string) get_user_meta( $user->ID, '_fw_mbr_verified', true );
			if ( '' !== $verified || '' !== FW_Membership_Verification::state( $user->ID ) ) {
				$data[] = array(
					'group_id'    => 'unysonplus-membership-account',
					'group_label' => __( 'Membership account', 'fw' ),
					'item_id'     => 'membership-account-' . $user->ID,
					'data'        => array(
						array( 'name' => __( 'Email confirmed', 'fw' ), 'value' => '' !== $verified ? $verified : __( 'no', 'fw' ) ),
						array( 'name' => __( 'Waiting for', 'fw' ), 'value' => FW_Membership_Verification::state( $user->ID ) ),
					),
				);
			}
		}
		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * @param string $email
	 * @param int    $page
	 *
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$user    = get_user_by( 'email', $email );
		$removed = false;
		$kept    = false;
		$msgs    = array();
		if ( $user ) {
			$removed = FW_Membership_Memberships::count( array( 'user_id' => $user->ID ) ) > 0;
			$removed = FW_Membership_Profile::erase( $user->ID ) > 0 || $removed;
			FW_Membership_Memberships::delete_for_user( $user->ID );
			$payments = FW_Membership_Installer::table( 'payments' );
			$n        = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$payments} SET user_id = 0, meta = NULL WHERE user_id = %d", $user->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $n ) {
				$kept   = true;
				$msgs[] = __( 'Payment records were kept for accounting but no longer point to the person.', 'fw' );
			}
			foreach ( array( FW_Membership_Verification::META_STATE, FW_Membership_Verification::META_TOKEN, '_fw_mbr_verified', '_fw_mbr_needs_approval', '_fw_mbr_registered_via' ) as $key ) {
				delete_user_meta( $user->ID, $key );
			}
			FW_Membership_Access::flush();
		}
		return array( 'items_removed' => $removed, 'items_retained' => $kept, 'messages' => $msgs, 'done' => true );
	}

	/** @internal A paragraph for the site's privacy policy guide. */
	public static function _policy_text() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'Membership', 'fw' ), wpautop( __( 'When you join, we keep which membership plans you hold, when they started and end, and a history of changes to them, so we can give you access to members-only content. If you sign up through a form, we may ask you to confirm your email address. You can ask us to export or erase this information.', 'fw' ) ) );
		}
	}
}
