<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The money side of a membership, in one place. Gateways report what happened here;
 * this class turns it into payment rows and membership changes through the service.
 *
 * Every outcome is idempotent: a payment service may send the same notification twice
 * (or the buyer returns before the webhook arrives), and the second call changes nothing.
 *
 *   start()             create the pending membership + payment, hand over to the gateway
 *   paid()              the first payment succeeded → the membership starts (or its trial)
 *   renewed()           a renewal was paid → access runs to the new period end
 *   renewal_failed()    a renewal failed → past due (access kept through the grace period)
 *   cancel_scheduled()  billing stops at period end → cancelled, access to that date
 *   ended()             the subscription ended → expired
 *   refunded()          a payment was refunded → refunded; access ends for a first payment
 *
 * Changes made in the site (a member cancels, an admin ends a membership) are passed on to
 * the gateway so billing stops there too; changes that came FROM the gateway are not sent
 * back (self::$from_gateway).
 */
class FW_Membership_Payments_Flow {

	/** @var bool True while applying something the payment service told us. */
	private static $from_gateway = false;

	/** Days of access kept after a paid period ends, while a late renewal can still arrive. */
	public static function grace_days() {
		return max( 0, (int) apply_filters( 'unysonplus_membership_renewal_grace_days', 3 ) );
	}

	public static function init() {
		add_action( 'unysonplus_membership_cancelled', array( __CLASS__, '_on_cancelled' ), 5, 2 );
		add_action( 'unysonplus_membership_expired', array( __CLASS__, '_on_expired' ), 5, 2 );
		add_action( 'unysonplus_membership_reactivated', array( __CLASS__, '_on_reactivated' ), 5, 2 );
	}

	/**
	 * What a buyer pays for a price: the one place prices are worked out, so the
	 * checkout shows exactly what is charged. Modules adjust it on
	 * `unysonplus_membership_quote` (coupons set discount / coupon, tax sets tax).
	 *
	 * @param array $plan
	 * @param array $price
	 * @param array $args coupon (code), user_id, country, region, vat_number
	 *
	 * @return array { currency, amount (one period), setup_fee, trial_days, recurring, due (before
	 *               discount), discount, coupon (array|null), discount_recurring (amount off each
	 *               renewal), tax, tax_rate, tax_label, tax_inclusive, subtotal, total (due now),
	 *               renewal_total (each renewal after the first), errors[] }
	 */
	public static function quote( array $plan, array $price, array $args = array() ) {
		$amount = 'free' === $price['billing'] ? 0.0 : (float) $price['amount'];
		$setup  = (float) $price['setup_fee'];
		$trial  = (int) $price['trial_days'];
		$q      = array(
			'currency'           => strtoupper( '' !== (string) $price['currency'] ? $price['currency'] : FW_Membership_Gateways::currency() ),
			'amount'             => $amount,
			'setup_fee'          => $setup,
			'trial_days'         => $trial,
			'recurring'          => FW_Membership_Payments::is_recurring( $price ),
			'due'                => round( ( $trial > 0 && FW_Membership_Payments::is_recurring( $price ) ? 0.0 : $amount ) + $setup, 2 ),
			'discount'           => 0.0,
			'coupon'             => null,
			'discount_recurring' => 0.0,
			'tax'                => 0.0,
			'tax_rate'           => 0.0,
			'tax_label'          => '',
			'tax_inclusive'      => false,
			'errors'             => array(),
		);
		/** Adjust a quote: discounts, tax. Add messages to `errors` to refuse it. */
		$q = (array) apply_filters( 'unysonplus_membership_quote', $q, $plan, $price, $args );

		$q['discount']      = round( min( max( 0.0, (float) $q['discount'] ), $q['due'] ), 2 );
		$q['subtotal']      = round( $q['due'] - $q['discount'], 2 );
		$q['tax']           = round( max( 0.0, (float) $q['tax'] ), 2 );
		$q['total']         = round( $q['subtotal'] + ( $q['tax_inclusive'] ? 0.0 : $q['tax'] ), 2 );
		$renew              = max( 0.0, $amount - (float) $q['discount_recurring'] );
		$q['renewal_total'] = $q['recurring'] ? round( $renew * ( $q['tax_inclusive'] ? 1 : ( 1 + (float) $q['tax_rate'] / 100 ) ), 2 ) : 0.0;
		return $q;
	}

	/**
	 * Start a checkout.
	 *
	 * @param int    $user_id
	 * @param array  $plan
	 * @param array  $price
	 * @param string $gateway_id
	 * @param array  $urls { success, cancel }
	 * @param array  $args replaces: a membership id this purchase replaces once paid (a plan change)
	 *
	 * @return array|WP_Error { redirect?: url, done?: bool, message?: string, payment_id, membership_id }
	 */
	public static function start( $user_id, array $plan, array $price, $gateway_id, array $urls, array $args = array() ) {
		/** Run a different kind of checkout (the Gifts module): return its result to use it instead. */
		$pre = apply_filters( 'unysonplus_membership_checkout_start', null, $user_id, $plan, $price, $gateway_id, $urls, $args );
		if ( null !== $pre ) {
			return $pre;
		}
		$replaces = (int) ( $args['replaces'] ?? 0 );
		if ( 'active' !== $plan['status'] || (int) $price['plan_id'] !== (int) $plan['id'] || 'active' !== ( $price['status'] ?? 'active' ) ) {
			return new WP_Error( 'fw_mbr_plan_closed', __( 'This plan or price is not available.', 'fw' ) );
		}
		$held = FW_Membership_Memberships::query( array( 'user_id' => $user_id, 'plan_id' => $plan['id'], 'status' => 'access', 'per_page' => 1 ) );
		if ( $held ) {
			return new WP_Error( 'fw_mbr_already', __( 'You already have this membership.', 'fw' ) );
		}
		if ( $plan['signup_limit'] && FW_Membership_Memberships::count( array( 'plan_id' => $plan['id'], 'status' => 'access' ) ) >= $plan['signup_limit'] ) {
			return new WP_Error( 'fw_mbr_full', __( 'Sorry, this membership is full.', 'fw' ) );
		}

		$q = self::quote( $plan, $price, array_merge( $args, array( 'user_id' => $user_id ) ) );
		if ( $q['errors'] ) {
			return new WP_Error( 'fw_mbr_quote', implode( ' ', $q['errors'] ) );
		}
		$currency = $q['currency'];

		// Nothing to pay, now or later: no gateway, the membership starts now.
		if ( $q['total'] <= 0 && ! $q['recurring'] ) {
			$mid = FW_Membership_Service::grant( $user_id, $plan['id'], array( 'source' => $q['coupon'] ? 'payment' : 'registration', 'price_id' => $price['id'], 'notify' => true ) );
			if ( is_wp_error( $mid ) ) {
				return $mid;
			}
			if ( $replaces ) {
				self::replace( $replaces, $plan );
			}
			/** A checkout completed (paid or free): coupons count the use here. */
			do_action( 'unysonplus_membership_checkout_completed', $q, $user_id, $mid, 0 );
			return array( 'done' => true, 'membership_id' => $mid, 'payment_id' => 0 );
		}

		$gateway = FW_Membership_Gateways::get( $gateway_id );
		if ( ! $gateway || ! isset( FW_Membership_Gateways::available_for( $price )[ $gateway_id ] ) ) {
			return new WP_Error( 'fw_mbr_gateway', __( 'Please choose a way to pay.', 'fw' ) );
		}

		// Reuse an abandoned attempt at the same plan rather than piling up pending rows.
		$pending = FW_Membership_Memberships::query( array( 'user_id' => $user_id, 'plan_id' => $plan['id'], 'status' => array( 'pending' ), 'per_page' => 1 ) );
		if ( $pending ) {
			$mid = $pending[0]['id'];
			FW_Membership_Service::set_billing( $mid, array( 'price_id' => $price['id'], 'gateway' => $gateway_id ) );
		} else {
			$mid = FW_Membership_Service::grant( $user_id, $plan['id'], array( 'source' => 'payment', 'price_id' => $price['id'], 'status' => 'pending' ) );
			if ( is_wp_error( $mid ) ) {
				return $mid;
			}
			FW_Membership_Service::set_billing( $mid, array( 'gateway' => $gateway_id ) );
		}

		$key = wp_generate_password( 20, false, false );
		$pid = FW_Membership_Payments::insert( array(
			'membership_id' => $mid,
			'user_id'       => $user_id,
			'plan_id'       => $plan['id'],
			'price_id'      => $price['id'],
			'type'          => 'payment',
			'status'        => 'pending',
			'amount'        => $q['total'],
			'tax'           => $q['tax'],
			'currency'      => $currency,
			'gateway'       => $gateway_id,
			'coupon'        => $q['coupon'] ? (string) $q['coupon']['code'] : '',
			'meta'          => array(
				'key'        => $key,
				'setup_fee'  => $q['setup_fee'],
				'trial_days' => $q['trial_days'],
				'replaces'   => $replaces,
				'quote'      => array_intersect_key( $q, array_flip( array( 'amount', 'due', 'discount', 'discount_recurring', 'subtotal', 'tax', 'tax_rate', 'tax_label', 'tax_inclusive', 'total', 'renewal_total' ) ) ),
				'tax_info'   => array_intersect_key( $args, array_flip( array( 'country', 'region', 'vat_number' ) ) ),
			),
		) );
		if ( ! $pid ) {
			return new WP_Error( 'fw_mbr_db', __( 'The payment could not be started. Please try again.', 'fw' ) );
		}

		$urls = array(
			'success' => add_query_arg( array( 'fw_mbr_payment' => $pid, 'k' => $key ), $urls['success'] ),
			'cancel'  => add_query_arg( array( 'fw_mbr_payment' => $pid, 'k' => $key, 'fw_mbr_cancelled' => 1 ), $urls['cancel'] ),
		);
		$result = $gateway->start( FW_Membership_Payments::get( $pid ), FW_Membership_Memberships::get( $mid ), $price, get_userdata( $user_id ), $urls );
		if ( is_wp_error( $result ) ) {
			FW_Membership_Payments::update( $pid, array( 'status' => 'failed', 'meta' => array_merge( FW_Membership_Payments::get( $pid )['meta'], array( 'error' => $result->get_error_message() ) ) ) );
			return $result;
		}
		return array_merge( (array) $result, array( 'payment_id' => $pid, 'membership_id' => $mid, 'key' => $key ) );
	}

	/**
	 * The first payment succeeded (or a free trial started).
	 *
	 * @param int   $payment_id
	 * @param array $args gateway_ref, subscription_id, status (active|trialing), period_end (unix), trial_end (unix), meta
	 *
	 * @return true|WP_Error
	 */
	public static function paid( $payment_id, array $args = array() ) {
		$p = FW_Membership_Payments::get( $payment_id );
		if ( ! $p ) {
			return new WP_Error( 'fw_mbr_no_payment', 'Unknown payment.' );
		}
		if ( 'paid' === $p['status'] ) {
			return true; // already done (webhook + return, or a repeated webhook)
		}
		FW_Membership_Payments::update( $payment_id, array(
			'status'      => 'paid',
			'gateway_ref' => (string) ( $args['gateway_ref'] ?? $p['gateway_ref'] ),
			'meta'        => array_merge( $p['meta'], (array) ( $args['meta'] ?? array() ), array( 'paid_at' => current_time( 'mysql' ) ) ),
		) );

		if ( ! $p['membership_id'] ) {
			// Paid for something that is not the buyer's membership (a gift).
			/** A payment with no membership was paid (the Gifts module makes the gift). */
			do_action( 'unysonplus_membership_payment_paid_other', FW_Membership_Payments::get( $payment_id ) );
			do_action( 'unysonplus_membership_payment_paid', FW_Membership_Payments::get( $payment_id ) );
			return true;
		}
		$activate = array( 'status' => ( $args['status'] ?? '' ) === 'trialing' ? 'trialing' : 'active' );
		if ( ! empty( $args['subscription_id'] ) ) {
			$activate['gateway_subscription_id'] = (string) $args['subscription_id'];
		}
		if ( ! empty( $args['period_end'] ) ) {
			$activate['renews_at'] = self::local( (int) $args['period_end'] );
			$activate['ends']      = self::local( (int) $args['period_end'] + self::grace_days() * DAY_IN_SECONDS );
		}
		if ( ! empty( $args['trial_end'] ) ) {
			$activate['trial_ends_at'] = self::local( (int) $args['trial_end'] );
		}
		self::$from_gateway = true;
		$r = FW_Membership_Service::activate( $p['membership_id'], true, $activate );
		self::$from_gateway = false;
		do_action( 'unysonplus_membership_checkout_completed', array( 'coupon' => '' !== (string) $p['coupon'] ? array( 'code' => $p['coupon'] ) : null ), $p['user_id'], $p['membership_id'], $payment_id );
		if ( ! empty( $p['meta']['replaces'] ) ) {
			$new = FW_Membership_Memberships::get( $p['membership_id'] );
			self::replace( (int) $p['meta']['replaces'], $new ? FW_Membership_Plans::get( $new['plan_id'] ) : null );
		}

		/** Fires when a payment is completed. */
		do_action( 'unysonplus_membership_payment_paid', FW_Membership_Payments::get( $payment_id ) );
		return $r;
	}

	/**
	 * A renewal was paid.
	 *
	 * @param array  $membership
	 * @param string $gateway_ref The invoice / charge id (dedupes repeated notifications).
	 * @param float  $amount
	 * @param string $currency
	 * @param int    $period_end unix
	 * @param array  $meta
	 *
	 * @return true|WP_Error
	 */
	public static function renewed( array $membership, $gateway_ref, $amount, $currency, $period_end, array $meta = array() ) {
		if ( FW_Membership_Payments::find_by_ref( (string) $membership['gateway'], (string) $gateway_ref ) ) {
			return true;
		}
		$pid = FW_Membership_Payments::insert( array(
			'membership_id' => $membership['id'],
			'user_id'       => $membership['user_id'],
			'plan_id'       => $membership['plan_id'],
			'price_id'      => $membership['price_id'],
			'type'          => 'renewal',
			'status'        => 'paid',
			'amount'        => (float) $amount,
			'currency'      => strtoupper( (string) $currency ),
			'gateway'       => (string) $membership['gateway'],
			'gateway_ref'   => (string) $gateway_ref,
			'meta'          => array_merge( $meta, array( 'paid_at' => current_time( 'mysql' ) ) ),
		) );
		self::$from_gateway = true;
		$r = FW_Membership_Service::renew_until( $membership['id'], self::local( (int) $period_end + self::grace_days() * DAY_IN_SECONDS ), self::local( (int) $period_end ) );
		self::$from_gateway = false;
		FW_Membership_Plan_Change::apply_due( $membership['id'] );
		do_action( 'unysonplus_membership_payment_paid', FW_Membership_Payments::get( $pid ) );
		return $r;
	}

	/**
	 * A renewal payment failed.
	 *
	 * @param array  $membership
	 * @param string $gateway_ref
	 * @param float  $amount
	 * @param string $currency
	 *
	 * @return true|WP_Error
	 */
	public static function renewal_failed( array $membership, $gateway_ref, $amount = 0.0, $currency = '' ) {
		if ( ! FW_Membership_Payments::find_by_ref( (string) $membership['gateway'], (string) $gateway_ref ) ) {
			FW_Membership_Payments::insert( array(
				'membership_id' => $membership['id'],
				'user_id'       => $membership['user_id'],
				'plan_id'       => $membership['plan_id'],
				'price_id'      => $membership['price_id'],
				'type'          => 'renewal',
				'status'        => 'failed',
				'amount'        => (float) $amount,
				'currency'      => strtoupper( (string) $currency ),
				'gateway'       => (string) $membership['gateway'],
				'gateway_ref'   => (string) $gateway_ref,
			) );
		}
		self::$from_gateway = true;
		$r = FW_Membership_Service::payment_failed( $membership['id'], __( 'The renewal payment failed.', 'fw' ) );
		self::$from_gateway = false;
		return $r;
	}

	/**
	 * Billing will stop at the end of the paid period.
	 *
	 * @param array $membership
	 * @param int   $period_end unix
	 */
	public static function cancel_scheduled( array $membership, $period_end ) {
		if ( 'cancelled' === $membership['status'] || FW_Membership_Plan_Change::scheduled_free( $membership ) ) {
			return; // already cancelled, or billing stops because the member moves to a free plan
		}
		FW_Membership_Memberships::update( $membership['id'], array( 'ends_at' => self::local( (int) $period_end ) ) );
		self::$from_gateway = true;
		FW_Membership_Service::cancel( $membership['id'] );
		self::$from_gateway = false;
	}

	/**
	 * The subscription ended at the payment service.
	 *
	 * @param array $membership
	 */
	public static function ended( array $membership ) {
		if ( in_array( $membership['status'], array( 'expired', 'refunded' ), true ) ) {
			return;
		}
		if ( FW_Membership_Plan_Change::scheduled_free( $membership ) ) {
			FW_Membership_Plan_Change::to_free_now( $membership );
			return;
		}
		self::$from_gateway = true;
		FW_Membership_Service::expire( $membership['id'], __( 'The subscription ended at the payment service.', 'fw' ) );
		self::$from_gateway = false;
	}

	/**
	 * A charge outside the renewal cycle (the difference for an upgrade).
	 *
	 * @param array  $membership
	 * @param string $gateway_ref
	 * @param float  $amount
	 * @param string $currency
	 * @param array  $meta
	 *
	 * @return int The payment id (0 when already recorded or nothing was charged).
	 */
	public static function extra_charge( array $membership, $gateway_ref, $amount, $currency, array $meta = array() ) {
		if ( (float) $amount <= 0 || FW_Membership_Payments::find_by_ref( (string) $membership['gateway'], (string) $gateway_ref ) ) {
			return 0;
		}
		$pid = FW_Membership_Payments::insert( array(
			'membership_id' => $membership['id'],
			'user_id'       => $membership['user_id'],
			'plan_id'       => $membership['plan_id'],
			'price_id'      => $membership['price_id'],
			'type'          => 'payment',
			'status'        => 'paid',
			'amount'        => (float) $amount,
			'currency'      => strtoupper( (string) $currency ),
			'gateway'       => (string) $membership['gateway'],
			'gateway_ref'   => (string) $gateway_ref,
			'meta'          => array_merge( $meta, array( 'paid_at' => current_time( 'mysql' ) ) ),
		) );
		do_action( 'unysonplus_membership_payment_paid', FW_Membership_Payments::get( $pid ) );
		return $pid;
	}

	/**
	 * The payment service resumed a subscription that was going to stop.
	 *
	 * @param array $membership
	 * @param int   $period_end unix
	 */
	public static function resumed( array $membership, $period_end ) {
		self::$from_gateway = true;
		FW_Membership_Service::reactivate( $membership['id'] );
		self::$from_gateway = false;
		if ( $period_end ) {
			FW_Membership_Memberships::update( $membership['id'], array( 'renews_at' => self::local( $period_end ), 'ends_at' => self::local( $period_end + self::grace_days() * DAY_IN_SECONDS ) ) );
		}
	}

	/**
	 * The payment service ended a pause (Stripe's resumes_at, or early).
	 *
	 * @param array $membership
	 * @param int   $period_end unix
	 */
	public static function unpaused( array $membership, $period_end ) {
		self::$from_gateway = true;
		FW_Membership_Service::resume( $membership['id'] );
		self::$from_gateway = false;
		if ( $period_end ) {
			FW_Membership_Memberships::update( $membership['id'], array( 'renews_at' => self::local( $period_end ), 'ends_at' => self::local( $period_end + self::grace_days() * DAY_IN_SECONDS ) ) );
		}
	}

	/** @return bool True while applying something the payment service told us. */
	public static function from_gateway() {
		return self::$from_gateway;
	}

	/**
	 * End the membership a plan change replaced (its subscription stops now).
	 *
	 * @param int        $old_id
	 * @param array|null $new_plan
	 */
	private static function replace( $old_id, $new_plan ) {
		$old = FW_Membership_Memberships::get( $old_id );
		if ( ! $old || ! in_array( $old['status'], FW_Membership_Memberships::ACCESS_STATUSES, true ) ) {
			return;
		}
		FW_Membership_Service::expire( $old_id, sprintf( __( 'Replaced by %s.', 'fw' ), $new_plan ? $new_plan['name'] : __( 'another plan', 'fw' ) ), array( 'replaced' => true ) );
	}

	/**
	 * A payment was refunded (at the payment service or by hand).
	 *
	 * @param int  $payment_id
	 * @param bool $end_access End the membership too (default for a first payment).
	 *
	 * @return true|WP_Error
	 */
	public static function refunded( $payment_id, $end_access = true ) {
		$p = FW_Membership_Payments::get( $payment_id );
		if ( ! $p || 'refunded' === $p['status'] ) {
			return true;
		}
		FW_Membership_Payments::update( $payment_id, array( 'status' => 'refunded', 'meta' => array_merge( $p['meta'], array( 'refunded_at' => current_time( 'mysql' ) ) ) ) );
		if ( $end_access && $p['membership_id'] ) {
			self::$from_gateway = true;
			FW_Membership_Service::refund( $p['membership_id'], __( 'Payment refunded.', 'fw' ) );
			self::$from_gateway = false;
			// A refunded subscription must not renew again.
			$m = FW_Membership_Memberships::get( $p['membership_id'] );
			$g = $m ? FW_Membership_Gateways::get( $m['gateway'] ) : null;
			if ( $g && $m['gateway_subscription_id'] ) {
				$g->cancel( $m, true );
			}
		}
		do_action( 'unysonplus_membership_payment_refunded', FW_Membership_Payments::get( $payment_id ) );
		return true;
	}

	/**
	 * @internal A member or an admin cancelled in the site: stop renewals at the gateway.
	 */
	public static function _on_cancelled( $m, $ctx ) {
		if ( self::$from_gateway || '' === (string) $m['gateway_subscription_id'] ) {
			return;
		}
		$g = FW_Membership_Gateways::get( $m['gateway'] );
		if ( $g ) {
			$r = $g->cancel( $m, false );
			if ( is_wp_error( $r ) ) {
				FW_Membership_Memberships::log( array( 'membership_id' => $m['id'], 'user_id' => $m['user_id'], 'plan_id' => $m['plan_id'], 'type' => 'gateway_error', 'note' => $r->get_error_message() ) );
			}
		}
	}

	/**
	 * @internal Reactivated in the site after a cancel: the subscription continues at the gateway.
	 */
	public static function _on_reactivated( $m, $ctx ) {
		if ( self::$from_gateway || '' === (string) $m['gateway_subscription_id'] ) {
			return;
		}
		$g = FW_Membership_Gateways::get( $m['gateway'] );
		if ( $g ) {
			$r = $g->resume( $m );
			if ( is_wp_error( $r ) ) {
				FW_Membership_Memberships::log( array( 'membership_id' => $m['id'], 'user_id' => $m['user_id'], 'plan_id' => $m['plan_id'], 'type' => 'gateway_error', 'note' => $r->get_error_message() ) );
			}
		}
	}

	/**
	 * @internal Ended in the site (End now): stop billing at the gateway immediately.
	 */
	public static function _on_expired( $m, $ctx ) {
		if ( self::$from_gateway || '' === (string) $m['gateway_subscription_id'] ) {
			return;
		}
		$g = FW_Membership_Gateways::get( $m['gateway'] );
		if ( $g ) {
			$r = $g->cancel( $m, true );
			if ( is_wp_error( $r ) ) {
				FW_Membership_Memberships::log( array( 'membership_id' => $m['id'], 'user_id' => $m['user_id'], 'plan_id' => $m['plan_id'], 'type' => 'gateway_error', 'note' => $r->get_error_message() ) );
			}
		}
	}

	/**
	 * A unix time as local site time (how every Membership date is stored).
	 *
	 * @param int $ts
	 *
	 * @return string
	 */
	public static function local( $ts ) {
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $ts ) );
	}
}
