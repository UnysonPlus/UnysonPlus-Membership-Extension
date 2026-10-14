<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Reminders and win-back, from the daily job (each sent once per membership and date):
 *
 *   renewal_reminder   N days before a subscription renews (with the amount)
 *   trial_ending       N days before a free trial turns into a paid membership
 *   expiring_soon      N days before a membership that will not renew ends
 *   win_back           N days after a membership ended, with a one-time discount code
 *                      (made with the Coupons module: one use, that person's old plan)
 */
class FW_Membership_Reminders {

	public static function init() {
		add_filter( 'unysonplus_membership_email_catalogue', array( __CLASS__, '_emails' ) );
	}

	/** @internal */
	public static function _emails( $c ) {
		$c['renewal_reminder'] = array(
			'label'   => __( 'Renewal reminder', 'fw' ),
			'to'      => 'member',
			'when'    => __( 'Days before a membership renews (Settings → Keeping members).', 'fw' ),
			'subject' => __( 'Your {plan} membership renews on {renewal_date}', 'fw' ),
			'body'    => __( "Hi {first_name},\n\nA quick note: your {plan} membership renews on {renewal_date} for {amount}. Nothing to do if you want to carry on. To change your card or your plan, visit your account:\n\n{account_url}", 'fw' ),
		);
		$c['trial_ending'] = array(
			'label'   => __( 'Free trial ending', 'fw' ),
			'to'      => 'member',
			'when'    => __( 'Days before a free trial ends.', 'fw' ),
			'subject' => __( 'Your free trial of {plan} ends on {renewal_date}', 'fw' ),
			'body'    => __( "Hi {first_name},\n\nYour free trial of {plan} ends on {renewal_date}, and the membership then continues for {amount}. If it is not for you, you can cancel before then from your account:\n\n{account_url}", 'fw' ),
		);
		$c['expiring_soon'] = array(
			'label'   => __( 'Membership ending soon', 'fw' ),
			'to'      => 'member',
			'when'    => __( 'Days before a membership that will not renew ends.', 'fw' ),
			'subject' => __( 'Your {plan} membership ends on {ends_at}', 'fw' ),
			'body'    => __( "Hi {first_name},\n\nYour {plan} membership ends on {ends_at}. To keep your access, renew here:\n\n{renew_url}", 'fw' ),
		);
		$c['win_back'] = array(
			'label'   => __( 'Win-back offer', 'fw' ),
			'to'      => 'member',
			'when'    => __( 'Days after a membership ended, with a one-time discount code.', 'fw' ),
			'subject' => __( 'We miss you: {coupon_label} if you come back', 'fw' ),
			'body'    => __( "Hi {first_name},\n\nYour {plan} membership ended a little while ago. If you would like to come back, here is {coupon_label} with the code {coupon_code}:\n\n{renew_url}", 'fw' ),
		);
		return $c;
	}

	private static function renew_url( array $m, $coupon = '' ) {
		$plan = FW_Membership_Plans::get( $m['plan_id'] );
		$url  = $plan && class_exists( 'FW_Membership_Gateways' ) ? FW_Membership_Gateways::checkout_url( array_filter( array( 'plan' => $plan['slug'], 'coupon' => $coupon ) ) ) : '';
		return '' !== $url ? $url : FW_Membership_Settings::account_url();
	}

	/** The daily run. */
	public static function run() {
		$s    = FW_Membership_Retention::settings();
		$now  = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$df   = get_option( 'date_format' );
		$soon = function ( $date, $days ) use ( $now ) {
			$t = $date ? strtotime( $date ) : 0;
			return $days > 0 && $t > $now && $t <= $now + $days * DAY_IN_SECONDS;
		};
		$plan_vars = function ( array $m ) {
			$plan  = FW_Membership_Plans::get( $m['plan_id'] );
			$price = $m['price_id'] ? FW_Membership_Plans::get_price( $m['price_id'] ) : null;
			return array( 'plan' => $plan ? $plan['name'] : '', 'amount' => $price ? FW_Membership_Plans::price_label( $price ) : '' );
		};

		foreach ( FW_Membership_Memberships::query( array( 'status' => array( 'active', 'trialing', 'cancelled' ), 'per_page' => 2000 ) ) as $m ) {
			$sub = '' !== (string) $m['gateway_subscription_id'];
			if ( 'trialing' === $m['status'] && $soon( $m['trial_ends_at'], (int) $s['remind_trial'] ) && FW_Membership_Retention::once( $m, 'trial:' . substr( (string) $m['trial_ends_at'], 0, 10 ) ) ) {
				FW_Membership_Emails::send( 'trial_ending', $m['user_id'], array_merge( $plan_vars( $m ), array( 'renewal_date' => date_i18n( $df, strtotime( $m['trial_ends_at'] ) ) ) ) );
			} elseif ( 'active' === $m['status'] && $sub && $soon( $m['renews_at'], (int) $s['remind_renewal'] ) && FW_Membership_Retention::once( $m, 'renewal:' . substr( (string) $m['renews_at'], 0, 10 ) ) ) {
				FW_Membership_Emails::send( 'renewal_reminder', $m['user_id'], array_merge( $plan_vars( $m ), array( 'renewal_date' => date_i18n( $df, strtotime( $m['renews_at'] ) ) ) ) );
			} elseif ( ( 'cancelled' === $m['status'] || ! $sub ) && $soon( $m['ends_at'], (int) $s['remind_expiring'] ) && FW_Membership_Retention::once( $m, 'expiring:' . substr( (string) $m['ends_at'], 0, 10 ) ) ) {
				FW_Membership_Emails::send( 'expiring_soon', $m['user_id'], array_merge( $plan_vars( $m ), array( 'ends_at' => date_i18n( $df, strtotime( $m['ends_at'] ) ), 'renew_url' => self::renew_url( $m ) ) ) );
			}
		}

		if ( $s['winback'] && class_exists( 'FW_Membership_Coupons' ) ) {
			$from = gmdate( 'Y-m-d H:i:s', $now - ( (int) $s['winback_days'] + 7 ) * DAY_IN_SECONDS );
			$to   = gmdate( 'Y-m-d H:i:s', $now - (int) $s['winback_days'] * DAY_IN_SECONDS );
			foreach ( FW_Membership_Memberships::query( array( 'status' => array( 'expired' ), 'per_page' => 2000 ) ) as $m ) {
				if ( ! $m['ends_at'] || $m['ends_at'] < $from || $m['ends_at'] > $to ) {
					continue;
				}
				// Not if they already came back.
				if ( FW_Membership_Memberships::query( array( 'user_id' => $m['user_id'], 'status' => 'access', 'per_page' => 1 ) ) || ! FW_Membership_Retention::once( $m, 'winback' ) ) {
					continue;
				}
				$codes = FW_Membership_Coupons::generate( array( 'type' => 'percent', 'amount' => (int) $s['winback_percent'], 'duration' => 'once', 'plans' => array( $m['plan_id'] ), 'expires_at' => gmdate( 'Y-m-d', $now + 30 * DAY_IN_SECONDS ), 'batch' => 'win-back', 'description' => 'Win-back' ), 1, 'BACK' );
				if ( is_wp_error( $codes ) || ! $codes ) {
					continue;
				}
				$c = FW_Membership_Coupons::get_by_code( $codes[0] );
				FW_Membership_Emails::send( 'win_back', $m['user_id'], array_merge( $plan_vars( $m ), array(
					'coupon_code'  => $c['code'],
					'coupon_label' => FW_Membership_Coupons::label( $c ),
					'renew_url'    => self::renew_url( $m, $c['code'] ),
				) ) );
			}
		}
	}
}
