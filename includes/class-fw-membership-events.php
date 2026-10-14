<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The events catalogue: one documented action per membership change, all with the
 * same arguments, so emails, the CRM connection, automations and add-ons listen to
 * one stream instead of reading the tables.
 *
 * Every change fires two actions, in this order:
 *
 *   unysonplus_membership_{event}( array $membership, array $context )
 *   unysonplus_membership_changed( string $event, array $membership, array $context )
 *
 * $membership is the record after the change (FW_Membership_Memberships::get()).
 * $context is [ 'from' => old status, 'to' => new status, 'plan' => plan array,
 *               'actor_id' => user who made the change (0 = the system), 'note' => string,
 *               'previous_plan_id' => int (plan_changed only) ].
 *
 * Only FW_Membership_Service fires these.
 */
class FW_Membership_Events {

	/**
	 * @return array event => description
	 */
	public static function catalogue() {
		return array(
			'pending'           => __( 'A membership was created but waits for email verification or approval before it starts.', 'fw' ),
			'joined'            => __( 'A membership started (any source, including a free plan). The context’s "notify" says whether the member should get the welcome email.', 'fw' ),
			'trial_started'     => __( 'A membership started with a trial.', 'fw' ),
			'renewed'           => __( 'A membership was renewed or extended.', 'fw' ),
			'payment_failed'    => __( 'A renewal payment failed; the membership is past due.', 'fw' ),
			'payment_recovered' => __( 'A past-due membership was paid and is active again.', 'fw' ),
			'plan_changed'      => __( 'A member moved to another plan (upgrade or downgrade).', 'fw' ),
			'paused'            => __( 'A membership was paused.', 'fw' ),
			'resumed'           => __( 'A paused membership was resumed.', 'fw' ),
			'cancelled'         => __( 'A membership was cancelled; access continues until its end date.', 'fw' ),
			'reactivated'       => __( 'A cancelled or expired membership was made active again.', 'fw' ),
			'expired'           => __( 'A membership ended and no longer gives access.', 'fw' ),
			'refunded'          => __( 'A membership was refunded and ended.', 'fw' ),
		);
	}

	/**
	 * @param string $event     A key of catalogue().
	 * @param array  $membership
	 * @param array  $context
	 */
	public static function fire( $event, array $membership, array $context = array() ) {
		do_action( 'unysonplus_membership_' . $event, $membership, $context );
		do_action( 'unysonplus_membership_changed', $event, $membership, $context );
	}
}
