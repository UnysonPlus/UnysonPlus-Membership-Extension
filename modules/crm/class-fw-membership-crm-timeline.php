<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Membership on the contact: every membership event and payment on the CRM timeline,
 * a Membership panel on the contact page (plans, dates, total paid, a link to the
 * member page), a Membership link in the CRM sidebar, and a follow-up task when a
 * renewal fails (if switched on).
 */
class FW_Membership_CRM_Timeline {

	public static function init() {
		add_filter( 'unysonplus_crm_activity_types', array( __CLASS__, '_types' ) );
		add_filter( 'unysonplus_crm_contact_panels', array( __CLASS__, '_panel' ), 10, 2 );
		add_filter( 'unysonplus_crm_nav', array( __CLASS__, '_nav' ) );
		add_action( 'unysonplus_membership_changed', array( __CLASS__, '_changed' ), 30, 3 );
		add_action( 'unysonplus_membership_payment_paid', array( __CLASS__, '_paid' ), 30 );
	}

	/** @internal */
	public static function _types( $types ) {
		$types['membership']         = array( 'label' => __( 'Membership', 'fw' ), 'icon' => 'dashicons-awards' );
		$types['membership_payment'] = array( 'label' => __( 'Membership payment', 'fw' ), 'icon' => 'dashicons-money-alt' );
		return $types;
	}

	/** @internal */
	public static function _changed( $event, $m, $ctx ) {
		$s = FW_Membership_CRM::settings();
		if ( ! $s['timeline'] && ! $s['failed_task'] ) {
			return;
		}
		$cid  = FW_Membership_CRM_Identity::contact_id( (int) $m['user_id'], false );
		$plan = FW_Membership_Plans::get( $m['plan_id'] );
		$name = $plan ? $plan['name'] : __( 'a plan', 'fw' );
		if ( ! $cid ) {
			return;
		}
		$titles = array(
			'joined'            => __( 'Joined %s', 'fw' ),
			'trial_started'     => __( 'Started a free trial of %s', 'fw' ),
			'renewed'           => __( 'Renewed %s', 'fw' ),
			'payment_failed'    => __( 'Renewal payment for %s failed', 'fw' ),
			'payment_recovered' => __( 'Renewal payment for %s went through', 'fw' ),
			'plan_changed'      => __( 'Moved to %s', 'fw' ),
			'paused'            => __( 'Paused %s', 'fw' ),
			'resumed'           => __( 'Resumed %s', 'fw' ),
			'cancelled'         => __( 'Cancelled %s', 'fw' ),
			'reactivated'       => __( 'Reactivated %s', 'fw' ),
			'expired'           => __( '%s ended', 'fw' ),
			'refunded'          => __( '%s refunded', 'fw' ),
			'pending'           => __( 'Signed up for %s (waiting)', 'fw' ),
		);
		if ( $s['timeline'] && isset( $titles[ $event ] ) ) {
			FW_CRM_Activity::log( 'contact', $cid, 'membership', sprintf( $titles[ $event ], $name ), array(
				'body' => (string) ( $ctx['note'] ?? '' ),
				'meta' => array( 'event' => $event, 'membership_id' => (int) $m['id'], 'plan_id' => (int) $m['plan_id'] ),
			) );
		}
		if ( 'payment_failed' === $event && $s['failed_task'] && class_exists( 'FW_CRM_Tasks' ) ) {
			$c = FW_CRM_Contacts::get( $cid );
			FW_CRM_Tasks::create( array(
				'title'       => sprintf( __( 'Follow up: %s renewal payment failed', 'fw' ), $name ),
				'type'        => 'todo',
				'object_type' => 'contact',
				'object_id'   => $cid,
				'owner_id'    => (int) ( $c['owner_id'] ?? 0 ),
				'due_at'      => gmdate( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			) );
		}
	}

	/** @internal */
	public static function _paid( $p ) {
		if ( ! $p || $p['amount'] <= 0 || ! FW_Membership_CRM::settings()['timeline'] ) {
			return;
		}
		$cid = FW_Membership_CRM_Identity::contact_id( (int) $p['user_id'], false );
		if ( ! $cid ) {
			return;
		}
		$plan = FW_Membership_Plans::get( $p['plan_id'] );
		FW_CRM_Activity::log( 'contact', $cid, 'membership_payment', sprintf( __( 'Paid %1$s for %2$s', 'fw' ), FW_Membership_Payments::money( $p['amount'], $p['currency'] ), $plan ? $plan['name'] : __( 'a membership', 'fw' ) ), array(
			'meta' => array( 'payment_id' => (int) $p['id'], 'amount' => (float) $p['amount'], 'currency' => (string) $p['currency'] ),
		) );
	}

	/** @internal */
	public static function _nav( $nav ) {
		foreach ( $nav as $gk => $group ) {
			if ( isset( $group['items'] ) && is_array( $group['items'] ) ) {
				$nav[ $gk ]['items']['membership'] = array(
					'label' => __( 'Membership', 'fw' ),
					'icon'  => 'dashicons-awards',
					'url'   => FW_Membership_Admin_Page_Url::get(),
					'cap'   => FW_Membership_Service::capability(),
				);
				break;
			}
		}
		return $nav;
	}

	/** @internal */
	public static function _panel( $panels, $contact ) {
		$uid = (int) ( $contact['user_id'] ?? 0 );
		if ( ! $uid || ! current_user_can( FW_Membership_Service::capability() ) ) {
			return $panels;
		}
		$panels['membership'] = array(
			'label'    => __( 'Membership', 'fw' ),
			'priority' => 15,
			'column'   => 'side',
			'callback' => function () use ( $uid ) {
				$ms     = FW_Membership_Memberships::query( array( 'user_id' => $uid, 'per_page' => 20 ) );
				$labels = array( 'pending' => __( 'Waiting', 'fw' ), 'trialing' => __( 'Trial', 'fw' ), 'active' => __( 'Active', 'fw' ), 'past_due' => __( 'Payment due', 'fw' ), 'paused' => __( 'Paused', 'fw' ), 'cancelled' => __( 'Cancelled', 'fw' ), 'expired' => __( 'Ended', 'fw' ), 'refunded' => __( 'Refunded', 'fw' ) );
				$date   = function ( $mysql ) {
					return $mysql ? date_i18n( get_option( 'date_format' ), strtotime( $mysql ) ) : '—';
				};
				if ( ! $ms ) {
					echo '<p class="description">' . esc_html__( 'Not a member.', 'fw' ) . '</p>';
				} else {
					echo '<ul class="fw-mbr-crm-panel">';
					foreach ( $ms as $m ) {
						$plan = FW_Membership_Plans::get( $m['plan_id'] );
						echo '<li><strong>' . esc_html( $plan ? $plan['name'] : '#' . $m['plan_id'] ) . '</strong> — ' . esc_html( $labels[ $m['status'] ] ?? $m['status'] );
						echo '<br /><span class="description">' . esc_html( sprintf( __( 'Since %s', 'fw' ), $date( $m['started_at'] ) ) );
						if ( $m['ends_at'] ) {
							echo ' · ' . esc_html( sprintf( __( 'until %s', 'fw' ), $date( $m['ends_at'] ) ) );
						}
						echo '</span></li>';
					}
					echo '</ul>';
				}
				if ( class_exists( 'FW_Membership_Payments' ) ) {
					$paid = FW_Membership_CRM_Summary::paid_total( $uid );
					if ( $paid ) {
						echo '<p>' . esc_html( sprintf( __( 'Paid in total: %s', 'fw' ), implode( ', ', $paid ) ) ) . '</p>';
					}
				}
				echo '<p><a href="' . esc_url( add_query_arg( 'member', $uid, FW_Membership_Admin_Page_Url::get( 'members' ) ) ) . '">' . esc_html__( 'Open the member page', 'fw' ) . ' →</a></p>';
			},
		);
		return $panels;
	}
}
