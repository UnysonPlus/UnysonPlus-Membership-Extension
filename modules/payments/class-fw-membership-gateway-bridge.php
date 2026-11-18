<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Membership on the shared Payments extension.
 *
 * While the Payments extension is active, each of Membership's own gateways (Stripe, PayPal,
 * bank transfer) is wrapped in this bridge, which sends every record to the side it was made on:
 *
 *   NEW purchases follow Membership → Payments → Settings → "Payment system". Set to the
 *   shared one, they go through the Payments extension (FW_Payments /
 *   FW_Payments_Subscriptions) and share its settings, ledger, refunds and webhooks with every
 *   other extension. Set to Membership's own (the default), they go to the wrapped gateway,
 *   exactly as before.
 *
 *   EXISTING memberships stay where they are, whatever the setting says now: one started on
 *   Membership's own gateway still renews at Membership's own webhook address and is
 *   cancelled, paused or refunded there; one started on the shared system is handled there,
 *   even after switching back. Nothing is moved and nobody is billed twice.
 *
 * How a record tells which side it is on:
 *   a membership whose gateway_subscription_id starts with "ps_"  -> Payments subscription id
 *   a payment row with meta.pay_charge                            -> Payments charge id
 *
 * The rest of Membership does not know the difference: it keeps talking to the
 * FW_Membership_Gateway interface and FW_Membership_Payments_Flow, exactly as before.
 */
class FW_Membership_Gateway_Bridge extends FW_Membership_Gateway {

	const SUB_PREFIX = 'ps_';
	const REF_PREFIX = 'fwp-';

	/** @var FW_Membership_Gateway The gateway being wrapped. */
	private $legacy;

	/** @var bool True while Membership itself is asking Payments for a change. */
	private static $asking = false;

	public function __construct( FW_Membership_Gateway $legacy ) {
		$this->legacy = $legacy;
	}

	/** @return FW_Membership_Gateway */
	public function legacy() {
		return $this->legacy;
	}

	/** Anything else (the Stripe gateway's api(), for the importers) is the legacy gateway's. */
	public function __call( $name, $args ) {
		return call_user_func_array( array( $this->legacy, $name ), $args );
	}

	/** @return FW_Payments_Gateway|null */
	private function shared() {
		return FW_Payments_Gateways::get( $this->id() );
	}

	/** @return bool New purchases go through the shared Payments extension. */
	private function on() {
		return FW_Membership_Gateways::uses_shared() && null !== $this->shared();
	}

	/** @return bool Payments is active and has this provider. */
	public static function can_wrap( FW_Membership_Gateway $g ) {
		return class_exists( 'FW_Payments' ) && ! ( $g instanceof self ) && null !== FW_Payments_Gateways::get( $g->id() );
	}

	/* ------------------------------------------------------------------ Identity and settings */

	public function id() {
		return $this->legacy->id();
	}

	public function title() {
		return $this->legacy->title();
	}

	public function checkout_label() {
		// Bank transfer's label is a setting: the shared one, when that is the system in use.
		if ( $this->on() && 'offline' === $this->id() ) {
			$label = trim( (string) $this->setting( 'label' ) );
			return '' !== $label ? $label : __( 'Bank transfer', 'fw' );
		}
		return $this->legacy->checkout_label();
	}

	public function checkout_description() {
		return $this->legacy->checkout_description();
	}

	/** On the shared system there are no fields here: the provider is set up in Payments → Settings. */
	public function settings_fields() {
		return $this->on() ? array() : $this->legacy->settings_fields();
	}

	public function setting( $key = null ) {
		return $this->on() ? $this->shared()->setting( $key ) : $this->legacy->setting( $key );
	}

	public function is_enabled() {
		return $this->on() ? $this->shared()->is_enabled() : $this->legacy->is_enabled();
	}

	public function is_ready() {
		return $this->on() ? $this->shared()->is_ready() : $this->legacy->is_ready();
	}

	public function supports_recurring() {
		return $this->on() ? $this->shared()->supports_recurring() : $this->legacy->supports_recurring();
	}

	public function supports_switch() {
		return $this->on() ? $this->shared()->supports_switch() : $this->legacy->supports_switch();
	}

	public function refunds_online() {
		$g = $this->shared();
		return ( $g && $g->refunds_online() ) || $this->legacy->refunds_online();
	}

	/* ------------------------------------------------------------------ Which side a record is on */

	/** @return int The Payments subscription id of a membership, or 0 when it is a legacy one. */
	public static function subscription_id( array $membership ) {
		$ref = (string) ( $membership['gateway_subscription_id'] ?? '' );
		return 0 === strpos( $ref, self::SUB_PREFIX ) ? (int) substr( $ref, strlen( self::SUB_PREFIX ) ) : 0;
	}

	/** @return int The Payments charge id of a payment row, or 0 when it is a legacy one. */
	public static function charge_id( array $payment ) {
		return (int) ( $payment['meta']['pay_charge'] ?? 0 );
	}

	private static function asking( callable $fn ) {
		$was          = self::$asking;
		self::$asking = true;
		$r            = $fn();
		self::$asking = $was;
		return $r;
	}

	/* ------------------------------------------------------------------ Starting a payment */

	public function start( array $payment, array $membership, array $price, $user, array $urls ) {
		if ( ! $this->on() ) {
			return $this->legacy->start( $payment, $membership, $price, $user, $urls );
		}
		$plan     = FW_Membership_Plans::get( $price['plan_id'] ?? ( $membership['plan_id'] ?? 0 ) );
		$name     = $plan ? $plan['name'] : __( 'Membership', 'fw' );
		$q        = (array) ( $payment['meta']['quote'] ?? array() );
		$country  = (string) ( $payment['meta']['tax_info']['country'] ?? '' );
		$customer = array(
			'user_id' => $user ? (int) $user->ID : 0,
			'email'   => $user ? (string) $user->user_email : '',
			'name'    => $user ? (string) $user->display_name : '',
			'country' => $country,
		);
		$common   = array(
			'source'    => 'membership',
			'source_id' => (int) $payment['id'], // the Membership payment row this pays for
			'currency'  => $payment['currency'],
			'customer'  => $customer,
			'gateway'   => $this->id(),
			'method'    => $this->id(),
			'urls'      => $urls,
			'meta'      => array( 'membership_id' => (int) ( $membership['id'] ?? 0 ) ),
		);
		// A code from the shared list has its use counted there (a code of Membership's own is not in it).
		if ( '' !== (string) ( $payment['coupon'] ?? '' ) && empty( $payment['meta']['gift'] ) ) {
			$common['coupon']       = (string) $payment['coupon'];
			$common['discount']     = (float) ( $q['discount'] ?? 0 );
			$common['coupon_items'] = array( array( 'type' => 'plan', 'id' => (int) ( $plan['id'] ?? 0 ), 'amount' => (float) ( $price['amount'] ?? 0 ) ) );
		}

		if ( FW_Membership_Payments::is_recurring( $price ) ) {
			$label = method_exists( 'FW_Membership_Plans', 'price_label' ) ? FW_Membership_Plans::price_label( $price ) : '';
			$args  = array_merge( $common, array(
				'amount'      => (float) $price['amount'],
				'bill_unit'   => (string) $price['billing'],
				'bill_count'  => max( 1, (int) $price['interval_count'] ),
				'trial_days'  => (int) $price['trial_days'],
				'setup_fee'   => (float) $price['setup_fee'],
				'due_now'     => (float) $payment['amount'],
				'tax_now'     => (float) $payment['tax'],
				'description' => trim( $name . ( '' !== $label ? ', ' . $label : '' ) ),
			) );
			$discount = self::discount_for( $payment );
			if ( $discount ) {
				$args['discount'] = $discount;
			}
			$tax = self::tax_for( $q, $country, $this->id() );
			if ( $tax ) {
				$args['tax'] = $tax;
			}
			$r = FW_Payments_Subscriptions::start( $args );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			FW_Membership_Payments::update( $payment['id'], array(
				'gateway_ref' => self::REF_PREFIX . $r['charge_id'],
				'meta'        => array_merge( (array) $payment['meta'], array( 'pay_charge' => (int) $r['charge_id'], 'pay_subscription' => (int) $r['id'] ) ),
			) );
			return array( 'redirect' => $r['redirect'] );
		}

		// One-off and lifetime prices: the checkout already worked out the total (discount and
		// tax included), and exactly that is charged.
		$r = FW_Payments::charge( array_merge( $common, array(
			'amount'      => (float) $payment['amount'],
			'tax'         => (float) $payment['tax'],
			'description' => $name,
		) ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$charge = FW_Payments::get( $r['id'] );
		FW_Membership_Payments::update( $payment['id'], array(
			// Bank transfer shows the payer a reference: Payments' own.
			'gateway_ref' => 'waiting' === $r['status'] && $charge ? $charge['reference'] : self::REF_PREFIX . $r['id'],
			'meta'        => array_merge( (array) FW_Membership_Payments::get( $payment['id'] )['meta'], array( 'pay_charge' => (int) $r['id'] ) ),
		) );
		if ( 'waiting' === $r['status'] ) {
			return array( 'done' => true, 'pending' => true, 'message' => (string) ( $r['instructions'] ?? '' ) );
		}
		if ( 'paid' === $r['status'] ) {
			return array( 'done' => true );
		}
		return array( 'redirect' => (string) ( $r['redirect'] ?? '' ) );
	}

	/**
	 * The checkout's coupon as a discount Payments understands. A gift is sent at its final,
	 * already-discounted price: no second discount.
	 *
	 * @return array|null
	 */
	private static function discount_for( array $payment ) {
		$code = (string) ( $payment['coupon'] ?? '' );
		if ( '' === $code || ! empty( $payment['meta']['gift'] ) || ! class_exists( 'FW_Membership_Coupons' ) ) {
			return null;
		}
		$c = FW_Membership_Coupons::get_by_code( $code );
		if ( ! $c || (float) $c['amount'] <= 0 ) {
			return null;
		}
		return array(
			'type'     => 'percent' === $c['type'] ? 'percent' : 'fixed',
			'amount'   => (float) $c['amount'],
			'duration' => in_array( $c['duration'], array( 'once', 'repeating', 'forever' ), true ) ? $c['duration'] : 'once',
			'months'   => (int) ( $c['months'] ?? 0 ),
			'code'     => (string) $c['code'],
		);
	}

	/**
	 * The checkout's tax as Payments understands it.
	 *
	 * @return array|null
	 */
	private static function tax_for( array $q, $country, $gateway_id ) {
		if ( class_exists( 'FW_Membership_Tax' ) ) {
			$s = FW_Membership_Tax::settings();
			if ( 'stripe' === $s['mode'] ) {
				// Worked out by Stripe itself. Other providers cannot, and charge no tax in this mode
				// (the same as before the switch).
				return 'stripe' === $gateway_id ? array( 'automatic' => true, 'vat_ids' => ! empty( $s['eu_vat'] ) ) : null;
			}
		}
		if ( empty( $q['tax_rate'] ) || (float) $q['tax_rate'] <= 0 ) {
			return null;
		}
		return array(
			'rate'      => (float) $q['tax_rate'],
			'inclusive' => ! empty( $q['tax_inclusive'] ),
			'label'     => preg_replace( '/\s*\(.*$/', '', (string) ( $q['tax_label'] ?? '' ) ),
			'country'   => (string) $country,
		);
	}

	public function confirm_return( array $payment ) {
		$cid = self::charge_id( $payment );
		if ( ! $cid ) {
			$this->legacy->confirm_return( $payment );
			return;
		}
		// Payments checks its own return link first; this covers a return without it.
		$charge = FW_Payments::get( $cid );
		$g      = $this->shared();
		if ( $charge && $g && in_array( $charge['status'], array( 'pending', 'waiting' ), true ) ) {
			$g->confirm_return( $charge );
		}
	}

	/** Membership's own webhook address: only memberships from before the switch still use it. */
	public function webhook( WP_REST_Request $request ) {
		return $this->legacy->webhook( $request );
	}

	/* ------------------------------------------------------------------ What Membership asks for */

	public function cancel( array $membership, $now = false ) {
		$sid = self::subscription_id( $membership );
		if ( ! $sid ) {
			return $this->legacy->cancel( $membership, $now );
		}
		$sub = FW_Payments_Subscriptions::get( $sid );
		if ( ! $sub || in_array( $sub['status'], array( 'ended', 'pending' ), true ) || ( ! $now && 'cancelling' === $sub['status'] ) ) {
			return true; // nothing left to stop
		}
		return self::asking( function () use ( $sid, $now ) {
			return FW_Payments_Subscriptions::cancel( $sid, (bool) $now );
		} );
	}

	public function resume( array $membership ) {
		$sid = self::subscription_id( $membership );
		if ( ! $sid ) {
			return $this->legacy->resume( $membership );
		}
		return self::asking( function () use ( $sid ) {
			return FW_Payments_Subscriptions::resume( $sid );
		} );
	}

	public function pause( array $membership, $until ) {
		$sid = self::subscription_id( $membership );
		if ( ! $sid ) {
			return '' === (string) $membership['gateway_subscription_id'] ? true : $this->legacy->pause( $membership, $until );
		}
		return self::asking( function () use ( $sid, $until ) {
			return FW_Payments_Subscriptions::pause( $sid, (int) $until );
		} );
	}

	public function unpause( array $membership ) {
		$sid = self::subscription_id( $membership );
		if ( ! $sid ) {
			return $this->legacy->unpause( $membership );
		}
		$sub = FW_Payments_Subscriptions::get( $sid );
		if ( ! $sub || 'paused' !== $sub['status'] ) {
			return true;
		}
		return self::asking( function () use ( $sid ) {
			return FW_Payments_Subscriptions::unpause( $sid );
		} );
	}

	public function switch_price( array $membership, array $price, $how ) {
		$sid = self::subscription_id( $membership );
		if ( ! $sid ) {
			return $this->legacy->switch_price( $membership, $price, $how );
		}
		if ( ! FW_Membership_Payments::is_recurring( $price ) ) {
			return new WP_Error( 'fw_mbr_no_switch', __( 'This membership has no subscription to change.', 'fw' ) );
		}
		$plan  = FW_Membership_Plans::get( $price['plan_id'] );
		$label = method_exists( 'FW_Membership_Plans', 'price_label' ) ? FW_Membership_Plans::price_label( $price ) : '';
		$new   = array(
			'amount'      => (float) $price['amount'],
			'bill_unit'   => (string) $price['billing'],
			'bill_count'  => max( 1, (int) $price['interval_count'] ),
			'description' => trim( ( $plan ? $plan['name'] : __( 'Membership', 'fw' ) ) . ( '' !== $label ? ', ' . $label : '' ) ),
		);
		return self::asking( function () use ( $sid, $new, $how ) {
			return FW_Payments_Subscriptions::switch_price( $sid, $new, 'prorate' === $how ? 'prorate' : 'none' );
		} );
	}

	public function discount( array $membership, $percent, $months ) {
		$sid = self::subscription_id( $membership );
		if ( ! $sid ) {
			return $this->legacy->discount( $membership, $percent, $months );
		}
		return self::asking( function () use ( $sid, $percent, $months ) {
			return FW_Payments_Subscriptions::discount( $sid, (int) $percent, (int) $months );
		} );
	}

	public function refund( array $payment ) {
		$cid = self::charge_id( $payment );
		if ( ! $cid ) {
			return $this->legacy->refund( $payment );
		}
		$charge = FW_Payments::get( $cid );
		if ( $charge && 'refunded' === $charge['status'] ) {
			return true; // already given back (in Payments, or at the provider)
		}
		$g = $this->shared();
		if ( ! $g || ! $g->refunds_online() ) {
			return new WP_Error( 'fw_mbr_no_refund', __( 'Refunds for this payment method are made outside the site. Mark it refunded here once done.', 'fw' ) );
		}
		return FW_Payments::refund( $cid );
	}

	public function manage_url( $user, $return_url ) {
		// The member's newest running membership on this provider decides which side to ask.
		foreach ( FW_Membership_Memberships::query( array( 'user_id' => (int) $user->ID, 'status' => array( 'active', 'trialing', 'past_due', 'cancelled', 'paused' ), 'per_page' => 20 ) ) as $m ) {
			if ( $m['gateway'] !== $this->id() || '' === (string) $m['gateway_subscription_id'] ) {
				continue;
			}
			$sid = self::subscription_id( $m );
			return $sid ? FW_Payments_Subscriptions::manage_url( $sid, $return_url ) : $this->legacy->manage_url( $user, $return_url );
		}
		return $this->legacy->manage_url( $user, $return_url );
	}

	/* ------------------------------------------------------------------ What Payments reports */

	/** Listen to Payments for everything that belongs to Membership. */
	public static function listen() {
		// Membership makes its own invoices for its payments.
		add_filter( 'unysonplus_payments_invoice_for', function ( $yes, $charge ) {
			return is_array( $charge ) && 'membership' === $charge['source'] ? false : $yes;
		}, 10, 2 );
		add_action( 'unysonplus_payments_paid', array( __CLASS__, '_paid' ) );
		add_action( 'unysonplus_payments_failed', array( __CLASS__, '_not_paid' ) );
		add_action( 'unysonplus_payments_cancelled', array( __CLASS__, '_not_paid' ) );
		add_action( 'unysonplus_payments_refunded', array( __CLASS__, '_refunded' ), 10, 2 );
		add_action( 'unysonplus_payments_subscription_renewal_failed', array( __CLASS__, '_renewal_failed' ), 10, 2 );
		add_action( 'unysonplus_payments_subscription_trial_ended', array( __CLASS__, '_trial_ended' ) );
		add_action( 'unysonplus_payments_subscription_cancelling', array( __CLASS__, '_cancelling' ) );
		add_action( 'unysonplus_payments_subscription_resumed', array( __CLASS__, '_resumed' ) );
		add_action( 'unysonplus_payments_subscription_unpaused', array( __CLASS__, '_unpaused' ) );
		add_action( 'unysonplus_payments_subscription_ended', array( __CLASS__, '_ended' ) );
		add_action( 'unysonplus_payments_subscription_upcoming', array( __CLASS__, '_upcoming' ), 10, 2 );
		add_filter( 'unysonplus_payments_stripe_session', array( __CLASS__, '_one_off_stripe_tax' ), 10, 2 );
	}

	/** A unix time for a stored local datetime, or 0. */
	private static function unix( $local ) {
		return $local ? (int) get_gmt_from_date( $local, 'U' ) : 0;
	}

	/** The membership a Payments subscription pays for. */
	private static function membership_for( array $sub ) {
		return 'membership' === $sub['source'] ? FW_Membership_Memberships::find_by_subscription( self::SUB_PREFIX . $sub['id'] ) : null;
	}

	/**
	 * @internal
	 */
	public static function _paid( $charge ) {
		if ( 'membership' !== $charge['source'] ) {
			return;
		}
		$ref  = self::REF_PREFIX . $charge['id'];
		$meta = array( 'pay_charge' => (int) $charge['id'] );
		$sub  = $charge['subscription_id'] ? FW_Payments_Subscriptions::get( $charge['subscription_id'] ) : null;

		if ( 'payment' === $charge['type'] ) {
			$p = FW_Membership_Payments::get( $charge['source_id'] );
			if ( ! $p || self::charge_id( $p ) !== (int) $charge['id'] ) {
				return;
			}
			// What was really charged (the provider's own tax and discounts are final there).
			if ( 'pending' === $p['status'] ) {
				FW_Membership_Payments::update( $p['id'], array( 'amount' => (float) $charge['amount'], 'tax' => (float) $charge['tax'] ) );
			}
			$args = array( 'gateway_ref' => $p['gateway_ref'], 'meta' => $meta );
			if ( $sub ) {
				$end                     = self::unix( $sub['period_end'] );
				$args['subscription_id'] = self::SUB_PREFIX . $sub['id'];
				$args['status']          = 'trialing' === $sub['status'] ? 'trialing' : 'active';
				$args['period_end']      = $end;
				if ( 'trialing' === $sub['status'] ) {
					$args['trial_end'] = $end;
				}
			}
			FW_Membership_Payments_Flow::paid( $p['id'], $args );
			return;
		}

		$m = $sub ? self::membership_for( $sub ) : null;
		if ( ! $m ) {
			return;
		}
		if ( 'renewal' === $charge['type'] ) {
			$end = self::unix( $sub['period_end'] );
			FW_Membership_Payments_Flow::renewed( $m, $ref, (float) $charge['amount'], $charge['currency'], $end ? $end : time() + MONTH_IN_SECONDS, $meta );
		} elseif ( 'extra' === $charge['type'] ) {
			FW_Membership_Payments_Flow::extra_charge( $m, $ref, (float) $charge['amount'], $charge['currency'], array_merge( $meta, array( 'reason' => 'plan_change' ) ) );
		}
	}

	/**
	 * @internal A first payment that failed or was abandoned: close Membership's waiting row.
	 */
	public static function _not_paid( $charge ) {
		if ( 'membership' !== $charge['source'] || 'payment' !== $charge['type'] ) {
			return;
		}
		$p = FW_Membership_Payments::get( $charge['source_id'] );
		if ( $p && 'pending' === $p['status'] && self::charge_id( $p ) === (int) $charge['id'] ) {
			FW_Membership_Payments::update( $p['id'], array( 'status' => 'cancelled' === $charge['status'] ? 'cancelled' : 'failed' ) );
		}
	}

	/**
	 * @internal Money given back in Payments or at the provider. Membership knows whole refunds only.
	 */
	public static function _refunded( $charge, $amount ) {
		if ( 'membership' !== $charge['source'] || 'refunded' !== $charge['status'] ) {
			return;
		}
		$p = 'payment' === $charge['type'] ? FW_Membership_Payments::get( $charge['source_id'] ) : FW_Membership_Payments::find_by_ref( $charge['gateway'], self::REF_PREFIX . $charge['id'] );
		if ( $p && self::charge_id( $p ) === (int) $charge['id'] ) {
			FW_Membership_Payments_Flow::refunded( $p['id'], 'payment' === $p['type'] );
		}
	}

	/**
	 * @internal
	 */
	public static function _renewal_failed( $sub, $charge ) {
		$m = self::membership_for( $sub );
		if ( $m ) {
			FW_Membership_Payments_Flow::renewal_failed( $m, self::REF_PREFIX . $charge['id'], (float) $charge['amount'], $charge['currency'] );
		}
	}

	/**
	 * @internal
	 */
	public static function _trial_ended( $sub ) {
		$m = self::membership_for( $sub );
		if ( $m && 'trialing' === $m['status'] ) {
			FW_Membership_Memberships::update( $m['id'], array( 'status' => 'active' ) );
		}
	}

	/**
	 * @internal Renewals were stopped at the provider or in Payments (not by Membership itself).
	 */
	public static function _cancelling( $sub ) {
		$m = self::$asking ? null : self::membership_for( $sub );
		if ( $m ) {
			FW_Membership_Payments_Flow::cancel_scheduled( $m, self::unix( $sub['period_end'] ) );
		}
	}

	/**
	 * @internal
	 */
	public static function _resumed( $sub ) {
		$m = self::$asking ? null : self::membership_for( $sub );
		if ( $m && 'cancelled' === $m['status'] ) {
			FW_Membership_Payments_Flow::resumed( $m, self::unix( $sub['period_end'] ) );
		}
	}

	/**
	 * @internal
	 */
	public static function _unpaused( $sub ) {
		$m = self::$asking ? null : self::membership_for( $sub );
		if ( $m && 'paused' === $m['status'] ) {
			FW_Membership_Payments_Flow::unpaused( $m, self::unix( $sub['period_end'] ) );
		}
	}

	/**
	 * @internal
	 */
	public static function _ended( $sub ) {
		$m = self::$asking ? null : self::membership_for( $sub );
		if ( $m ) {
			FW_Membership_Payments_Flow::ended( $m );
		}
	}

	/**
	 * @internal
	 */
	public static function _upcoming( $sub, $data ) {
		$m = self::membership_for( $sub );
		if ( $m ) {
			/** A renewal is coming: { date (unix), amount, currency, card_exp_month, card_exp_year, card_last4 }. */
			do_action( 'unysonplus_membership_renewal_upcoming', $m, $data );
		}
	}

	/**
	 * @internal A one-off membership payment while tax is worked out by Stripe: let Stripe add it.
	 */
	public static function _one_off_stripe_tax( $params, $charge ) {
		if ( 'membership' !== $charge['source'] || ! class_exists( 'FW_Membership_Tax' ) ) {
			return $params;
		}
		$s = FW_Membership_Tax::settings();
		if ( 'stripe' !== $s['mode'] ) {
			return $params;
		}
		$params['automatic_tax']              = array( 'enabled' => 'true' );
		$params['billing_address_collection'] = 'required';
		if ( ! empty( $s['eu_vat'] ) ) {
			$params['tax_id_collection'] = array( 'enabled' => 'true' );
		}
		return $params;
	}
}
