<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Membership triggers and actions for CRM automations, on the contract agreed with the
 * CRM (crm.md → "Automation registration"):
 *
 *   filter unysonplus_crm_automation_triggers  slug => { label, group, description, fields }
 *   filter unysonplus_crm_automation_actions   slug => { label, group, description, settings, callback }
 *   action unysonplus_crm_automation_event     ( $slug, $contact_id, $context )
 *
 * Slugs are namespaced membership.*. Context values are plain (they are stored with the
 * automation run) and carry a dedupe key. Action callbacks take a contact id and run in
 * a background job with no current user.
 *
 * Renewal and end dates are not "N days before" triggers: they are managed date fields
 * on the contact (FW_Membership_CRM_Summary), which the CRM's "date reached" trigger
 * uses for reminders before, on and after.
 */
class FW_Membership_CRM_Automations {

	const EVENTS = array( 'joined', 'trial_started', 'renewed', 'payment_failed', 'cancelled', 'expired', 'plan_changed' );

	public static function init() {
		add_filter( 'unysonplus_crm_automation_triggers', array( __CLASS__, '_triggers' ) );
		add_filter( 'unysonplus_crm_automation_actions', array( __CLASS__, '_actions' ) );
		add_action( 'unysonplus_membership_changed', array( __CLASS__, '_changed' ), 40, 3 );
	}

	/** @return array The plan choices (loaded lazily by the CRM). */
	public static function plan_options() {
		$out = array();
		foreach ( FW_Membership_Plans::all() as $p ) {
			$out[ $p['id'] ] = $p['name'];
		}
		return $out;
	}

	/** @internal */
	public static function _triggers( $triggers ) {
		$group  = __( 'Membership', 'fw' );
		$plan   = array( 'plan_id' => array( 'label' => __( 'Plan', 'fw' ), 'type' => 'select', 'options' => array( __CLASS__, 'plan_options' ) ) );
		$labels = array(
			'joined'         => array( __( 'Member joined a plan', 'fw' ), __( 'A membership started (paid, free or added by hand).', 'fw' ) ),
			'trial_started'  => array( __( 'Member started a free trial', 'fw' ), '' ),
			'renewed'        => array( __( 'Membership renewed', 'fw' ), __( 'A renewal was paid.', 'fw' ) ),
			'payment_failed' => array( __( 'Renewal payment failed', 'fw' ), __( 'The membership is past due while the payment is retried.', 'fw' ) ),
			'cancelled'      => array( __( 'Membership cancelled', 'fw' ), __( 'It will not renew; access continues to its end date.', 'fw' ) ),
			'expired'        => array( __( 'Membership ended', 'fw' ), '' ),
			'plan_changed'   => array( __( 'Member changed plan', 'fw' ), __( 'The plan is the new one.', 'fw' ) ),
		);
		foreach ( $labels as $event => $l ) {
			$triggers[ 'membership.' . $event ] = array(
				'label'       => $l[0],
				'group'       => $group,
				'description' => $l[1],
				'fields'      => $plan,
			);
		}
		return $triggers;
	}

	/** @internal */
	public static function _actions( $actions ) {
		$group = __( 'Membership', 'fw' );
		$plan  = array( 'plan_id' => array( 'label' => __( 'Plan', 'fw' ), 'type' => 'select', 'options' => array( __CLASS__, 'plan_options' ) ) );
		$actions['membership.grant_plan'] = array(
			'label'       => __( 'Give a membership plan', 'fw' ),
			'group'       => $group,
			'description' => __( 'The contact needs a WordPress account (it is linked by email).', 'fw' ),
			'settings'    => array_merge( $plan, array( 'days' => array( 'label' => __( 'For how many days (0 = the plan’s own length)', 'fw' ), 'type' => 'number' ) ) ),
			'callback'    => array( __CLASS__, 'grant_plan' ),
		);
		$actions['membership.extend'] = array(
			'label'    => __( 'Extend a membership', 'fw' ),
			'group'    => $group,
			'settings' => array_merge( $plan, array( 'days' => array( 'label' => __( 'Days', 'fw' ), 'type' => 'number' ) ) ),
			'callback' => array( __CLASS__, 'extend' ),
		);
		$actions['membership.cancel'] = array(
			'label'    => __( 'Cancel a membership', 'fw' ),
			'group'    => $group,
			'settings' => $plan,
			'callback' => array( __CLASS__, 'cancel' ),
		);
		return $actions;
	}

	/** @internal Fire the CRM event for a membership event. */
	public static function _changed( $event, $m, $ctx ) {
		if ( ! in_array( $event, self::EVENTS, true ) ) {
			return;
		}
		$cid = FW_Membership_CRM_Identity::contact_id( (int) $m['user_id'] );
		if ( ! $cid ) {
			return;
		}
		$plan    = FW_Membership_Plans::get( $m['plan_id'] );
		$context = array(
			'plan_id'       => (int) $m['plan_id'],
			'plan_name'     => $plan ? (string) $plan['name'] : '',
			'membership_id' => (int) $m['id'],
			'user_id'       => (int) $m['user_id'],
			'status'        => (string) $m['status'],
			'expires_at'    => (string) $m['ends_at'],
			'renews_at'     => (string) $m['renews_at'],
			'dedupe'        => $event . ':' . (int) $m['id'] . ':' . ( 'renewed' === $event ? (string) $m['renews_at'] : ( 'plan_changed' === $event ? (string) $m['plan_id'] : '' ) ),
		);
		if ( 'plan_changed' === $event ) {
			$context['previous_plan_id'] = (int) ( $ctx['previous_plan_id'] ?? 0 );
		}
		do_action( 'unysonplus_crm_automation_event', 'membership.' . $event, $cid, $context );
	}

	/**
	 * The WordPress account behind a contact.
	 *
	 * @param int $contact_id
	 *
	 * @return int|WP_Error
	 */
	private static function user_for( $contact_id ) {
		$c = FW_CRM_Contacts::get( (int) $contact_id );
		if ( ! $c ) {
			return new WP_Error( 'fw_mbr_crm_contact', __( 'Contact not found.', 'fw' ) );
		}
		if ( ! empty( $c['user_id'] ) ) {
			return (int) $c['user_id'];
		}
		$u = ! empty( $c['email'] ) ? get_user_by( 'email', $c['email'] ) : null;
		if ( ! $u ) {
			return new WP_Error( 'fw_mbr_crm_no_user', __( 'This contact has no WordPress account to give a membership to.', 'fw' ) );
		}
		$l = FW_CRM_Contacts::link_user( (int) $contact_id, (int) $u->ID );
		return is_wp_error( $l ) ? $l : (int) $u->ID;
	}

	/** Action: give a plan. */
	public static function grant_plan( $contact_id, array $settings, array $context ) {
		$uid = self::user_for( $contact_id );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$days = (int) ( $settings['days'] ?? 0 );
		$r    = FW_Membership_Service::grant( $uid, (int) ( $settings['plan_id'] ?? 0 ), array_filter( array(
			'source'     => 'crm_automation',
			'notify'     => true,
			'ends'       => $days > 0 ? gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $days * DAY_IN_SECONDS ) : '', // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			'source_ref' => (string) ( $context['automation_id'] ?? '' ),
		) ) );
		if ( is_wp_error( $r ) && 'fw_mbr_already' === $r->get_error_code() ) {
			return true; // already holds it: nothing to do
		}
		return is_wp_error( $r ) ? $r : true;
	}

	/** Action: extend the membership of a plan. */
	public static function extend( $contact_id, array $settings, array $context ) {
		$m = self::membership( $contact_id, (int) ( $settings['plan_id'] ?? 0 ) );
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		$days = max( 1, (int) ( $settings['days'] ?? 0 ) );
		return FW_Membership_Service::extend( $m['id'], '+' . $days . ' days' );
	}

	/** Action: cancel the membership of a plan (access continues to its end date). */
	public static function cancel( $contact_id, array $settings, array $context ) {
		$m = self::membership( $contact_id, (int) ( $settings['plan_id'] ?? 0 ) );
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		return FW_Membership_Service::cancel( $m['id'] );
	}

	private static function membership( $contact_id, $plan_id ) {
		$uid = self::user_for( $contact_id );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$ms = FW_Membership_Memberships::query( array( 'user_id' => $uid, 'plan_id' => $plan_id, 'status' => 'access', 'per_page' => 1 ) );
		return $ms ? $ms[0] : new WP_Error( 'fw_mbr_crm_no_membership', __( 'The contact does not hold that plan.', 'fw' ) );
	}
}
