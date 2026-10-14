<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Failed payment recovery. The payment service retries the card (Stripe Smart Retries,
 * PayPal's retry threshold); meanwhile the member keeps access for the grace period,
 * gets follow-up emails on the days set, and every email can carry {update_card_url}:
 * a signed link that logs nobody in by itself, but takes a logged-in member straight to
 * the payment service's card page (and others to log in first). Members whose card
 * expires before the next renewal are warned when the payment service announces it.
 */
class FW_Membership_Dunning {

	public static function init() {
		add_filter( 'unysonplus_membership_renewal_grace_days', array( __CLASS__, '_grace' ) );
		add_filter( 'unysonplus_membership_email_vars', array( __CLASS__, '_vars' ), 10, 3 );
		add_filter( 'unysonplus_membership_email_catalogue', array( __CLASS__, '_emails' ) );
		add_action( 'unysonplus_membership_renewal_upcoming', array( __CLASS__, '_upcoming' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, '_card_link' ), 1 );
	}

	/** @internal */
	public static function _grace( $days ) {
		return (int) FW_Membership_Retention::settings()['grace_days'];
	}

	/**
	 * A link that takes the member to update their card.
	 *
	 * @param int $user_id
	 *
	 * @return string
	 */
	public static function card_url( $user_id ) {
		$exp = time() + 14 * DAY_IN_SECONDS;
		$sig = hash_hmac( 'sha256', 'card|' . (int) $user_id . '|' . $exp, wp_salt( 'auth' ) );
		return add_query_arg( array( 'fw_mbr_card' => (int) $user_id, 'e' => $exp, 's' => substr( $sig, 0, 32 ) ), home_url( '/' ) );
	}

	/** @internal */
	public static function _vars( $vars, $key, $user ) {
		if ( ! isset( $vars['update_card_url'] ) ) {
			$vars['update_card_url'] = self::card_url( $user->ID );
		}
		return $vars;
	}

	/** @internal Follow the card link: log in if needed, then off to the card page. */
	public static function _card_link() {
		// phpcs:disable WordPress.Security.NonceVerification -- a signed, expiring link.
		if ( ! isset( $_GET['fw_mbr_card'], $_GET['e'], $_GET['s'] ) ) {
			return;
		}
		$uid = (int) $_GET['fw_mbr_card'];
		$exp = (int) $_GET['e'];
		$ok  = $exp > time() && hash_equals( substr( hash_hmac( 'sha256', 'card|' . $uid . '|' . $exp, wp_salt( 'auth' ) ), 0, 32 ), sanitize_key( wp_unslash( $_GET['s'] ) ) );
		// phpcs:enable
		$account = FW_Membership_Settings::account_url();
		if ( ! $ok ) {
			wp_safe_redirect( $account );
			exit;
		}
		if ( get_current_user_id() !== $uid ) {
			// The link does not log anyone in: whoever opens it logs in as themselves.
			wp_safe_redirect( FW_Membership_Settings::login_url( add_query_arg( array() ) ) );
			exit;
		}
		foreach ( FW_Membership_Memberships::query( array( 'user_id' => $uid, 'status' => array( 'active', 'trialing', 'past_due', 'cancelled', 'paused' ), 'per_page' => 20 ) ) as $m ) {
			$g = '' !== (string) $m['gateway_subscription_id'] ? FW_Membership_Gateways::get( $m['gateway'] ) : null;
			if ( $g ) {
				$url = $g->manage_url( wp_get_current_user(), $account );
				if ( is_string( $url ) && '' !== $url ) {
					wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- the payment service's page.
					exit;
				}
			}
		}
		wp_safe_redirect( $account );
		exit;
	}

	/** @internal */
	public static function _emails( $c ) {
		$c['payment_failed']['body'] = __( "Hi {first_name},\n\nWe could not take the payment for your {plan} membership. Your access continues for now while we try again. Please update your card so nothing is interrupted:\n\n{update_card_url}", 'fw' );
		$c['payment_reminder'] = array(
			'label'   => __( 'Payment still due', 'fw' ),
			'to'      => 'member',
			'when'    => __( 'Days after a failed renewal (Settings → Keeping members), while it is still unpaid.', 'fw' ),
			'subject' => __( 'Your {plan} membership is about to end', 'fw' ),
			'body'    => __( "Hi {first_name},\n\nWe still could not take the payment for your {plan} membership, and it ends on {ends_at} unless the card is updated:\n\n{update_card_url}", 'fw' ),
		);
		$c['card_expiring'] = array(
			'label'   => __( 'Card expiring', 'fw' ),
			'to'      => 'member',
			'when'    => __( 'Before a renewal, when the card on file expires first (card payments).', 'fw' ),
			'subject' => __( 'Your card expires before your next renewal', 'fw' ),
			'body'    => __( "Hi {first_name},\n\nThe card ending in {card_last4} expires before your {plan} membership renews on {renewal_date}. Update it here so your membership carries on:\n\n{update_card_url}", 'fw' ),
		);
		return $c;
	}

	/** @internal A renewal is coming: warn when the card will have expired. */
	public static function _upcoming( $m, $info ) {
		if ( ! FW_Membership_Retention::settings()['card_expiring'] || empty( $info['card_exp_year'] ) || empty( $info['date'] ) ) {
			return;
		}
		// A card is good to the end of its expiry month.
		$card_end = gmmktime( 23, 59, 59, (int) $info['card_exp_month'] + 1, 0, (int) $info['card_exp_year'] );
		if ( $card_end >= (int) $info['date'] || ! FW_Membership_Retention::once( $m, 'card:' . $info['card_exp_year'] . '-' . $info['card_exp_month'] ) ) {
			return;
		}
		$plan = FW_Membership_Plans::get( $m['plan_id'] );
		FW_Membership_Emails::send( 'card_expiring', $m['user_id'], array(
			'plan'         => $plan ? $plan['name'] : '',
			'card_last4'   => (string) $info['card_last4'],
			'renewal_date' => date_i18n( get_option( 'date_format' ), (int) $info['date'] ),
		) );
	}

	/** The daily follow-ups for memberships still past due. */
	public static function follow_ups() {
		$days = array_filter( array_map( 'intval', explode( ',', (string) FW_Membership_Retention::settings()['dunning_days'] ) ) );
		if ( ! $days ) {
			return;
		}
		global $wpdb;
		$e   = FW_Membership_Installer::table( 'membership_events' );
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		foreach ( FW_Membership_Memberships::query( array( 'status' => array( 'past_due' ), 'per_page' => 500 ) ) as $m ) {
			$failed = (string) $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$e} WHERE membership_id = %d AND type = 'payment_failed' ORDER BY id DESC LIMIT 1", $m['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( '' === $failed ) {
				continue;
			}
			$since = (int) floor( ( $now - strtotime( $failed ) ) / DAY_IN_SECONDS );
			foreach ( $days as $d ) {
				if ( $since >= $d && FW_Membership_Retention::once( $m, 'dunning:' . substr( $failed, 0, 10 ) . ':' . $d ) ) {
					$plan = FW_Membership_Plans::get( $m['plan_id'] );
					FW_Membership_Emails::send( 'payment_reminder', $m['user_id'], array(
						'plan'    => $plan ? $plan['name'] : '',
						'ends_at' => $m['ends_at'] ? date_i18n( get_option( 'date_format' ), strtotime( $m['ends_at'] ) ) : '',
					) );
					break; // one email a day at most
				}
			}
		}
	}
}
