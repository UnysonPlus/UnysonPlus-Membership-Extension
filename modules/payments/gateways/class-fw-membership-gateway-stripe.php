<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Cards and wallets through Stripe Checkout (Stripe's hosted payment page), with your
 * own Stripe account. No card data touches the site.
 *
 *  - One-off and lifetime prices: a Checkout session in `payment` mode.
 *  - Prices that renew: a Checkout session in `subscription` mode, billed by Stripe
 *    itself (Stripe Billing), so renewals never depend on WordPress's scheduler. Trials
 *    and setup fees go into the session.
 *  - Webhooks (POST /wp-json/fw-membership/v1/webhooks/stripe), each verified with the
 *    endpoint's signing secret (HMAC-SHA256 over "timestamp.payload", 5-minute
 *    tolerance): checkout.session.completed, invoice.paid (renewals),
 *    invoice.payment_failed, customer.subscription.updated (cancel at period end),
 *    customer.subscription.deleted, charge.refunded.
 *  - The buyer's return is confirmed against the session too, because webhooks can be late.
 *  - Members update their card through Stripe's customer portal.
 *
 * Talks to the REST API directly (wp_remote_request), pinned to one API version.
 */
class FW_Membership_Gateway_Stripe extends FW_Membership_Gateway {

	const API     = 'https://api.stripe.com/v1/';
	const VERSION = '2024-06-20';

	public function id() {
		return 'stripe';
	}

	public function title() {
		return __( 'Card (Stripe)', 'fw' );
	}

	public function checkout_label() {
		return __( 'Card', 'fw' );
	}

	public function checkout_description() {
		return __( 'Pay securely with a card or a wallet such as Apple Pay or Google Pay on the next page.', 'fw' );
	}

	public function supports_recurring() {
		return true;
	}

	public function settings_fields() {
		$hook = rest_url( 'fw-membership/v1/webhooks/stripe' );
		return array(
			'mode'                => array( 'label' => __( 'Mode', 'fw' ), 'type' => 'select', 'choices' => array( 'test' => __( 'Test (no real money)', 'fw' ), 'live' => __( 'Live', 'fw' ) ) ),
			'test_secret_key'     => array( 'label' => __( 'Test secret key', 'fw' ), 'type' => 'password', 'desc' => __( 'Stripe Dashboard → Developers → API keys (sk_test_…). A restricted key works too.', 'fw' ) ),
			'test_webhook_secret' => array( 'label' => __( 'Test webhook signing secret', 'fw' ), 'type' => 'password', 'desc' => sprintf( __( 'Add an endpoint in Stripe → Developers → Webhooks for %s with the events checkout.session.completed, invoice.paid, invoice.payment_failed, customer.subscription.updated, customer.subscription.deleted and charge.refunded, then paste its signing secret (whsec_…).', 'fw' ), $hook ) ),
			'live_secret_key'     => array( 'label' => __( 'Live secret key', 'fw' ), 'type' => 'password', 'desc' => __( 'sk_live_…', 'fw' ) ),
			'live_webhook_secret' => array( 'label' => __( 'Live webhook signing secret', 'fw' ), 'type' => 'password', 'desc' => __( 'The live endpoint\'s signing secret (whsec_…).', 'fw' ) ),
		);
	}

	private function live() {
		return 'live' === $this->setting( 'mode' );
	}

	private function secret_key() {
		return (string) $this->setting( $this->live() ? 'live_secret_key' : 'test_secret_key' );
	}

	private function webhook_secret() {
		return (string) $this->setting( $this->live() ? 'live_webhook_secret' : 'test_webhook_secret' );
	}

	public function is_ready() {
		return 0 === strpos( $this->secret_key(), $this->live() ? 'sk_live_' : 'sk_test_' ) || 0 === strpos( $this->secret_key(), $this->live() ? 'rk_live_' : 'rk_test_' );
	}

	/**
	 * Call the Stripe API.
	 *
	 * @param string $method GET | POST | DELETE
	 * @param string $path   e.g. checkout/sessions
	 * @param array  $params
	 *
	 * @return array|WP_Error The decoded response.
	 */
	public function api( $method, $path, array $params = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $this->secret_key(),
				'Stripe-Version' => self::VERSION,
			),
		);
		$url = self::API . ltrim( $path, '/' );
		if ( 'GET' === $method ) {
			$url = $params ? $url . '?' . http_build_query( $params ) : $url;
		} else {
			$args['body'] = $params;
			if ( 'POST' === $method && ! empty( $params['_idempotency'] ) ) {
				$args['headers']['Idempotency-Key'] = (string) $params['_idempotency'];
				unset( $args['body']['_idempotency'] );
			}
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 400 || ! is_array( $body ) ) {
			$msg = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : 'Stripe returned HTTP ' . $code;
			return new WP_Error( 'fw_mbr_stripe', $msg, array( 'status' => $code ) );
		}
		return $body;
	}

	/**
	 * The member's Stripe customer (one per mode), created on first use.
	 *
	 * @param WP_User $user
	 *
	 * @return string|WP_Error
	 */
	private function customer( $user ) {
		$meta = '_fw_mbr_stripe_customer_' . ( $this->live() ? 'live' : 'test' );
		$id   = (string) get_user_meta( $user->ID, $meta, true );
		if ( '' !== $id ) {
			return $id;
		}
		$c = $this->api( 'POST', 'customers', array(
			'email'    => $user->user_email,
			'name'     => $user->display_name,
			'metadata' => array( 'wp_user_id' => $user->ID, 'site' => home_url( '/' ) ),
		) );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		update_user_meta( $user->ID, $meta, $c['id'] );
		return (string) $c['id'];
	}

	public function start( array $payment, array $membership, array $price, $user, array $urls ) {
		$customer = $this->customer( $user );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		$plan      = FW_Membership_Plans::get( $membership['plan_id'] );
		$currency  = strtolower( $payment['currency'] );
		$recurring = FW_Membership_Payments::is_recurring( $price );
		$item      = array(
			'quantity'   => 1,
			'price_data' => array(
				'currency'     => $currency,
				'unit_amount'  => FW_Membership_Payments::minor_units( (float) $price['amount'], $currency ),
				'product_data' => array( 'name' => $plan ? $plan['name'] : __( 'Membership', 'fw' ) ),
			),
		);
		if ( $recurring ) {
			$item['price_data']['recurring'] = array( 'interval' => $price['billing'], 'interval_count' => max( 1, (int) $price['interval_count'] ) );
		}
		$items = array( $item );
		if ( (float) $price['setup_fee'] > 0 ) {
			$items[] = array(
				'quantity'   => 1,
				'price_data' => array(
					'currency'     => $currency,
					'unit_amount'  => FW_Membership_Payments::minor_units( (float) $price['setup_fee'], $currency ),
					'product_data' => array( 'name' => __( 'Setup fee', 'fw' ) ),
				),
			);
		}
		$meta   = array( 'fw_mbr_payment' => $payment['id'], 'fw_mbr_membership' => $membership['id'], 'site' => home_url( '/' ) );
		$params = array(
			'mode'                => $recurring ? 'subscription' : 'payment',
			'customer'            => $customer,
			'client_reference_id' => (string) $payment['id'],
			'line_items'          => $items,
			'metadata'            => $meta,
			// {CHECKOUT_SESSION_ID} must reach Stripe unencoded; Stripe fills it in.
			'success_url'         => $urls['success'] . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => $urls['cancel'],
			'_idempotency'        => 'fw-mbr-checkout-' . $payment['id'],
		);
		if ( $recurring ) {
			$params['subscription_data'] = array( 'metadata' => $meta );
			if ( (int) $price['trial_days'] > 0 ) {
				$params['subscription_data']['trial_period_days'] = (int) $price['trial_days'];
			}
		} else {
			$params['payment_intent_data'] = array( 'metadata' => $meta );
		}
		/** Filters the Stripe Checkout session parameters (add tax, allow promotion codes…). */
		$params  = (array) apply_filters( 'unysonplus_membership_stripe_session', $params, $payment, $membership, $price );
		if ( isset( $params['_fw_error'] ) ) {
			return new WP_Error( 'fw_mbr_stripe', (string) $params['_fw_error'] );
		}
		$session = $this->api( 'POST', 'checkout/sessions', $params );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		FW_Membership_Payments::update( $payment['id'], array( 'gateway_ref' => $session['id'] ) );
		return array( 'redirect' => $session['url'] );
	}

	/**
	 * Apply a completed Checkout session (from the webhook or the buyer's return).
	 *
	 * @param array $session
	 *
	 * @return true|WP_Error|null Null when it is not ours or not complete.
	 */
	public function apply_session( array $session ) {
		$pid = (int) ( $session['metadata']['fw_mbr_payment'] ?? $session['client_reference_id'] ?? 0 );
		$p   = $pid ? FW_Membership_Payments::get( $pid ) : null;
		if ( ! $p || 'stripe' !== $p['gateway'] || 'complete' !== ( $session['status'] ?? '' ) ) {
			return null;
		}
		if ( ! in_array( $session['payment_status'] ?? '', array( 'paid', 'no_payment_required' ), true ) ) {
			return null; // e.g. a delayed bank payment: wait for its own notification
		}
		$args = array( 'gateway_ref' => $session['id'], 'meta' => array( 'payment_intent' => (string) ( $session['payment_intent'] ?? '' ), 'customer' => (string) ( $session['customer'] ?? '' ) ) );
		// What Stripe actually charged (Stripe Tax and discounts are final there).
		if ( isset( $session['amount_total'] ) && 'pending' === $p['status'] ) {
			$unit = FW_Membership_Payments::minor_units( 1, (string) ( $session['currency'] ?? $p['currency'] ) );
			FW_Membership_Payments::update( $pid, array_filter( array(
				'amount' => (float) $session['amount_total'] / $unit,
				'tax'    => isset( $session['total_details']['amount_tax'] ) ? (float) $session['total_details']['amount_tax'] / $unit : null,
			), function ( $v ) {
				return null !== $v;
			} ) );
		}
		if ( ! empty( $session['subscription'] ) ) {
			$sub = is_array( $session['subscription'] ) ? $session['subscription'] : $this->api( 'GET', 'subscriptions/' . $session['subscription'] );
			if ( is_wp_error( $sub ) ) {
				return $sub;
			}
			$args['subscription_id'] = (string) $sub['id'];
			$args['status']          = 'trialing' === ( $sub['status'] ?? '' ) ? 'trialing' : 'active';
			$args['period_end']      = (int) ( $sub['current_period_end'] ?? 0 );
			if ( ! empty( $sub['trial_end'] ) ) {
				$args['trial_end'] = (int) $sub['trial_end'];
			}
			$args['meta']['invoice'] = (string) ( $sub['latest_invoice'] ?? '' );
		}
		return FW_Membership_Payments_Flow::paid( $pid, $args );
	}

	public function confirm_return( array $payment ) {
		if ( 'pending' !== $payment['status'] || 0 !== strpos( (string) $payment['gateway_ref'], 'cs_' ) ) {
			return;
		}
		$session = $this->api( 'GET', 'checkout/sessions/' . $payment['gateway_ref'] );
		if ( ! is_wp_error( $session ) ) {
			$this->apply_session( $session );
		}
	}

	/**
	 * Verify a webhook signature header against a raw body.
	 *
	 * @param string $payload
	 * @param string $header  Stripe-Signature
	 * @param string $secret
	 * @param int    $tolerance seconds
	 *
	 * @return bool
	 */
	public static function verify_signature( $payload, $header, $secret, $tolerance = 300 ) {
		if ( '' === (string) $secret || '' === (string) $header ) {
			return false;
		}
		$t   = 0;
		$sig = array();
		foreach ( explode( ',', (string) $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$t = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$sig[] = $kv[1];
			}
		}
		if ( ! $t || ! $sig || abs( time() - $t ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
		foreach ( $sig as $s ) {
			if ( hash_equals( $expected, $s ) ) {
				return true;
			}
		}
		return false;
	}

	public function webhook( WP_REST_Request $request ) {
		$payload = (string) $request->get_body();
		if ( ! self::verify_signature( $payload, (string) $request->get_header( 'stripe_signature' ), $this->webhook_secret() ) ) {
			return new WP_Error( 'fw_mbr_signature', 'Invalid signature.', array( 'status' => 400 ) );
		}
		$event = json_decode( $payload, true );
		$obj   = (array) ( $event['data']['object'] ?? array() );
		$type  = (string) ( $event['type'] ?? '' );

		switch ( $type ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				$r = $this->apply_session( $obj );
				if ( is_wp_error( $r ) ) {
					return new WP_Error( 'fw_mbr_stripe', $r->get_error_message(), array( 'status' => 500 ) ); // Stripe retries
				}
				break;

			case 'invoice.paid':
				$reason = (string) ( $obj['billing_reason'] ?? '' );
				$m      = in_array( $reason, array( 'subscription_cycle', 'subscription_update' ), true ) ? FW_Membership_Memberships::find_by_subscription( (string) ( $obj['subscription'] ?? '' ) ) : null;
				if ( $m ) {
					$amount = (float) ( $obj['amount_paid'] ?? 0 ) / FW_Membership_Payments::minor_units( 1, (string) ( $obj['currency'] ?? 'usd' ) );
					$meta   = array( 'payment_intent' => (string) ( $obj['payment_intent'] ?? '' ), 'invoice' => (string) $obj['id'] );
					if ( 'subscription_update' === $reason ) {
						// The difference for an upgrade: a charge, not a new period.
						FW_Membership_Payments_Flow::extra_charge( $m, (string) $obj['id'], $amount, (string) ( $obj['currency'] ?? '' ), array_merge( $meta, array( 'reason' => 'plan_change' ) ) );
					} else {
						$end = (int) ( $obj['lines']['data'][0]['period']['end'] ?? $obj['period_end'] ?? 0 );
						FW_Membership_Payments_Flow::renewed( $m, (string) $obj['id'], $amount, (string) ( $obj['currency'] ?? '' ), $end, $meta );
					}
				}
				break;

			case 'invoice.upcoming':
				// A renewal is coming (Stripe sends it days ahead): its date, amount and card.
				$m = FW_Membership_Memberships::find_by_subscription( (string) ( $obj['subscription'] ?? '' ) );
				if ( $m ) {
					$s    = $this->api( 'GET', 'subscriptions/' . $m['gateway_subscription_id'], array( 'expand' => array( 'default_payment_method' ) ) );
					$card = is_wp_error( $s ) ? array() : (array) ( $s['default_payment_method']['card'] ?? array() );
					/** A renewal is coming: { date (unix), amount, currency, card_exp_month, card_exp_year, card_last4 }. */
					do_action( 'unysonplus_membership_renewal_upcoming', $m, array(
						'date'           => (int) ( $obj['next_payment_attempt'] ?? $obj['period_end'] ?? 0 ),
						'amount'         => (float) ( $obj['amount_due'] ?? 0 ) / FW_Membership_Payments::minor_units( 1, (string) ( $obj['currency'] ?? 'usd' ) ),
						'currency'       => strtoupper( (string) ( $obj['currency'] ?? '' ) ),
						'card_exp_month' => (int) ( $card['exp_month'] ?? 0 ),
						'card_exp_year'  => (int) ( $card['exp_year'] ?? 0 ),
						'card_last4'     => (string) ( $card['last4'] ?? '' ),
					) );
				}
				break;

			case 'invoice.payment_failed':
				$m = FW_Membership_Memberships::find_by_subscription( (string) ( $obj['subscription'] ?? '' ) );
				if ( $m && 'subscription_create' !== ( $obj['billing_reason'] ?? '' ) ) {
					FW_Membership_Payments_Flow::renewal_failed( $m, (string) $obj['id'], (float) ( $obj['amount_due'] ?? 0 ) / FW_Membership_Payments::minor_units( 1, (string) ( $obj['currency'] ?? 'usd' ) ), (string) ( $obj['currency'] ?? '' ) );
				}
				break;

			case 'customer.subscription.updated':
				$m = FW_Membership_Memberships::find_by_subscription( (string) ( $obj['id'] ?? '' ) );
				if ( $m ) {
					if ( ! empty( $obj['cancel_at_period_end'] ) ) {
						FW_Membership_Payments_Flow::cancel_scheduled( $m, (int) ( $obj['current_period_end'] ?? 0 ) );
					} elseif ( 'paused' === $m['status'] && empty( $obj['pause_collection'] ) ) {
						FW_Membership_Payments_Flow::unpaused( $m, (int) ( $obj['current_period_end'] ?? 0 ) );
					} elseif ( 'cancelled' === $m['status'] && in_array( $obj['status'] ?? '', array( 'active', 'trialing' ), true ) ) {
						FW_Membership_Payments_Flow::resumed( $m, (int) ( $obj['current_period_end'] ?? 0 ) );
					} elseif ( 'trialing' === $m['status'] && 'active' === ( $obj['status'] ?? '' ) ) {
						FW_Membership_Memberships::update( $m['id'], array( 'status' => 'active' ) );
					}
				}
				break;

			case 'customer.subscription.deleted':
				$m = FW_Membership_Memberships::find_by_subscription( (string) ( $obj['id'] ?? '' ) );
				if ( $m ) {
					FW_Membership_Payments_Flow::ended( $m );
				}
				break;

			case 'charge.refunded':
				$pi = (string) ( $obj['payment_intent'] ?? '' );
				if ( '' !== $pi && ! empty( $obj['refunded'] ) ) {
					global $wpdb;
					$t   = FW_Membership_Installer::table( 'payments' );
					$pid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE gateway = 'stripe' AND meta LIKE %s ORDER BY id DESC LIMIT 1", '%' . $wpdb->esc_like( '"payment_intent":"' . $pi . '"' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
					if ( $pid ) {
						$p = FW_Membership_Payments::get( $pid );
						FW_Membership_Payments_Flow::refunded( $pid, 'payment' === $p['type'] );
					}
				}
				break;
		}
		return rest_ensure_response( array( 'received' => true ) );
	}

	public function cancel( array $membership, $now = false ) {
		$sub = (string) $membership['gateway_subscription_id'];
		if ( '' === $sub ) {
			return true;
		}
		$r = $now ? $this->api( 'DELETE', 'subscriptions/' . $sub ) : $this->api( 'POST', 'subscriptions/' . $sub, array( 'cancel_at_period_end' => 'true' ) );
		return is_wp_error( $r ) ? $r : true;
	}

	public function supports_switch() {
		return true;
	}

	/**
	 * The Stripe Price for one of our recurring prices, created on first use (a
	 * subscription can only move to a saved Price, not to inline price data).
	 *
	 * @param array $price
	 *
	 * @return string|WP_Error
	 */
	private function price_id( array $price ) {
		$key = 'stripe_price_' . ( $this->live() ? 'live' : 'test' );
		if ( ! empty( $price['gateway_refs'][ $key ] ) ) {
			return (string) $price['gateway_refs'][ $key ];
		}
		$plan     = FW_Membership_Plans::get( $price['plan_id'] );
		$currency = strtolower( '' !== (string) $price['currency'] ? $price['currency'] : FW_Membership_Gateways::currency() );
		$r        = $this->api( 'POST', 'prices', array(
			'currency'     => $currency,
			'unit_amount'  => FW_Membership_Payments::minor_units( (float) $price['amount'], $currency ),
			'recurring'    => array( 'interval' => $price['billing'], 'interval_count' => max( 1, (int) $price['interval_count'] ) ),
			'product_data' => array( 'name' => $plan ? $plan['name'] : __( 'Membership', 'fw' ) ),
			'metadata'     => array( 'fw_mbr_price' => $price['id'], 'site' => home_url( '/' ) ),
			'_idempotency' => 'fw-mbr-price-' . $price['id'] . '-' . ( $this->live() ? 'live' : 'test' ),
		) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		FW_Membership_Plans::set_price_ref( $price['id'], $key, $r['id'] );
		return (string) $r['id'];
	}

	public function switch_price( array $membership, array $price, $how ) {
		$sub = (string) $membership['gateway_subscription_id'];
		if ( '' === $sub || ! FW_Membership_Payments::is_recurring( $price ) ) {
			return new WP_Error( 'fw_mbr_stripe_switch', __( 'This membership has no subscription to change.', 'fw' ) );
		}
		$s = $this->api( 'GET', 'subscriptions/' . $sub );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$item = (string) ( $s['items']['data'][0]['id'] ?? '' );
		$pid  = $this->price_id( $price );
		if ( is_wp_error( $pid ) ) {
			return $pid;
		}
		if ( '' === $item ) {
			return new WP_Error( 'fw_mbr_stripe_switch', __( 'The subscription could not be read from Stripe.', 'fw' ) );
		}
		$params = array(
			'items'              => array( array( 'id' => $item, 'price' => $pid ) ),
			'proration_behavior' => 'prorate' === $how ? 'always_invoice' : 'none',
			'metadata'           => array( 'fw_mbr_price' => $price['id'] ),
		);
		if ( 'prorate' === $how ) {
			// A card that fails the difference leaves the subscription as it was.
			$params['payment_behavior'] = 'error_if_incomplete';
		}
		$r = $this->api( 'POST', 'subscriptions/' . $sub, $params );
		return is_wp_error( $r ) ? $r : true;
	}

	public function pause( array $membership, $until ) {
		$sub = (string) $membership['gateway_subscription_id'];
		if ( '' === $sub ) {
			return true;
		}
		$r = $this->api( 'POST', 'subscriptions/' . $sub, array( 'pause_collection' => array( 'behavior' => 'void', 'resumes_at' => (int) $until ) ) );
		return is_wp_error( $r ) ? $r : true;
	}

	public function unpause( array $membership ) {
		$sub = (string) $membership['gateway_subscription_id'];
		if ( '' === $sub ) {
			return true;
		}
		$r = $this->api( 'POST', 'subscriptions/' . $sub, array( 'pause_collection' => '' ) );
		return is_wp_error( $r ) ? $r : true;
	}

	public function discount( array $membership, $percent, $months ) {
		$sub = (string) $membership['gateway_subscription_id'];
		if ( '' === $sub ) {
			return new WP_Error( 'fw_mbr_stripe', __( 'This membership has no subscription.', 'fw' ) );
		}
		$key  = 'fw_mbr_retention_' . ( $this->live() ? 'live' : 'test' ) . '_' . (int) $percent . '_' . (int) $months;
		$refs = (array) get_option( 'fw_ext_membership_stripe_retention_coupons', array() );
		if ( empty( $refs[ $key ] ) ) {
			$c = $this->api( 'POST', 'coupons', array( 'percent_off' => (int) $percent, 'duration' => 'repeating', 'duration_in_months' => (int) $months, 'name' => sprintf( 'Stay: %d%% off', (int) $percent ), '_idempotency' => $key ) );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
			$refs[ $key ] = (string) $c['id'];
			update_option( 'fw_ext_membership_stripe_retention_coupons', $refs, false );
		}
		$r = $this->api( 'POST', 'subscriptions/' . $sub, array( 'discounts' => array( array( 'coupon' => $refs[ $key ] ) ) ) );
		return is_wp_error( $r ) ? $r : true;
	}

	public function resume( array $membership ) {
		$sub = (string) $membership['gateway_subscription_id'];
		if ( '' === $sub ) {
			return true;
		}
		$r = $this->api( 'POST', 'subscriptions/' . $sub, array( 'cancel_at_period_end' => 'false' ) );
		return is_wp_error( $r ) ? $r : true;
	}

	public function refund( array $payment ) {
		$pi = (string) ( $payment['meta']['payment_intent'] ?? '' );
		if ( '' === $pi && ! empty( $payment['meta']['invoice'] ) ) {
			$inv = $this->api( 'GET', 'invoices/' . $payment['meta']['invoice'] );
			$pi  = is_wp_error( $inv ) ? '' : (string) ( $inv['payment_intent'] ?? '' );
		}
		if ( '' === $pi ) {
			return new WP_Error( 'fw_mbr_stripe_refund', __( 'No Stripe payment found to refund. Refund it in the Stripe Dashboard, then mark it refunded here.', 'fw' ) );
		}
		$r = $this->api( 'POST', 'refunds', array( 'payment_intent' => $pi, '_idempotency' => 'fw-mbr-refund-' . $payment['id'] ) );
		return is_wp_error( $r ) ? $r : true;
	}

	public function manage_url( $user, $return_url ) {
		$cid = (string) get_user_meta( $user->ID, '_fw_mbr_stripe_customer_' . ( $this->live() ? 'live' : 'test' ), true );
		if ( '' === $cid ) {
			return '';
		}
		$s = $this->api( 'POST', 'billing_portal/sessions', array( 'customer' => $cid, 'return_url' => $return_url ) );
		return is_wp_error( $s ) ? $s : (string) $s['url'];
	}
}
