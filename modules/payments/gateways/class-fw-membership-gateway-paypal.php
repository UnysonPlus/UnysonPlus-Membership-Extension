<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * PayPal (REST API, no SDK): Orders for one-off prices, Subscriptions for prices that
 * repeat. The buyer approves on PayPal's own page and comes back; webhooks (verified by
 * PayPal's verify-webhook-signature call) keep the membership in step.
 *
 * A repeating price becomes a PayPal product (per plan) and a PayPal plan (per price,
 * trial, setup fee, tax and discount), created on first use and remembered on the price.
 * PayPal cannot move a subscription to another plan without the buyer approving it
 * again, so plan changes are not offered for PayPal subscriptions.
 */
class FW_Membership_Gateway_PayPal extends FW_Membership_Gateway {

	public function id() {
		return 'paypal';
	}

	public function title() {
		return __( 'PayPal', 'fw' );
	}

	public function checkout_description() {
		return __( 'Pay with your PayPal account or a card on PayPal’s page.', 'fw' );
	}

	public function supports_recurring() {
		return true;
	}

	public function settings_fields() {
		$hook = rest_url( 'fw-membership/v1/webhooks/paypal' );
		return array(
			'mode'          => array( 'label' => __( 'Mode', 'fw' ), 'type' => 'select', 'choices' => array( 'sandbox' => __( 'Sandbox (no real money)', 'fw' ), 'live' => __( 'Live', 'fw' ) ) ),
			'client_id'     => array( 'label' => __( 'Client ID', 'fw' ), 'type' => 'text', 'desc' => __( 'PayPal Developer Dashboard → Apps & Credentials → your app (use the Sandbox app while testing).', 'fw' ) ),
			'client_secret' => array( 'label' => __( 'Secret', 'fw' ), 'type' => 'password' ),
			'webhook_id'    => array( 'label' => __( 'Webhook ID', 'fw' ), 'type' => 'text', 'desc' => sprintf( __( 'In the same app, add a webhook for %s with the events CHECKOUT.ORDER.APPROVED, PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.REFUNDED, BILLING.SUBSCRIPTION.ACTIVATED, BILLING.SUBSCRIPTION.CANCELLED, BILLING.SUBSCRIPTION.EXPIRED, BILLING.SUBSCRIPTION.SUSPENDED, BILLING.SUBSCRIPTION.PAYMENT.FAILED, PAYMENT.SALE.COMPLETED and PAYMENT.SALE.REFUNDED, then paste its ID.', 'fw' ), $hook ) ),
		);
	}

	public function is_ready() {
		return '' !== trim( (string) $this->setting( 'client_id' ) ) && '' !== trim( (string) $this->setting( 'client_secret' ) );
	}

	private function live() {
		return 'live' === $this->setting( 'mode' );
	}

	private function base() {
		return $this->live() ? 'https://api-m.paypal.com/' : 'https://api-m.sandbox.paypal.com/';
	}

	/** @return string|WP_Error An access token (cached until shortly before it expires). */
	private function token() {
		$key = 'fw_mbr_pp_token_' . md5( $this->setting( 'client_id' ) . $this->live() );
		$t   = get_transient( $key );
		if ( $t ) {
			return $t;
		}
		$res = wp_remote_post( $this->base() . 'v1/oauth2/token', array(
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $this->setting( 'client_id' ) . ':' . $this->setting( 'client_secret' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			'body'    => array( 'grant_type' => 'client_credentials' ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'fw_mbr_paypal', __( 'PayPal did not accept the Client ID and Secret.', 'fw' ) );
		}
		set_transient( $key, $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 120 ) );
		return (string) $body['access_token'];
	}

	/**
	 * Call the PayPal REST API (JSON).
	 *
	 * @param string $method
	 * @param string $path
	 * @param array  $body
	 * @param string $idempotency PayPal-Request-Id
	 *
	 * @return array|WP_Error
	 */
	public function api( $method, $path, array $body = array(), $idempotency = '' ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Prefer' => 'return=representation' ),
		);
		if ( '' !== $idempotency ) {
			$args['headers']['PayPal-Request-Id'] = $idempotency;
		}
		if ( 'GET' !== $method ) {
			$args['body'] = $body ? wp_json_encode( $body ) : '{}';
		}
		$res = wp_remote_request( $this->base() . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$out  = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 ) {
			$msg = is_array( $out ) ? (string) ( $out['details'][0]['description'] ?? $out['message'] ?? '' ) : '';
			return new WP_Error( 'fw_mbr_paypal', '' !== $msg ? $msg : 'PayPal returned HTTP ' . $code, array( 'status' => $code ) );
		}
		return is_array( $out ) ? $out : array();
	}

	/** "9.00" (or "900" for zero-decimal currencies). */
	private static function value( $amount, $currency ) {
		return 1 === FW_Membership_Payments::minor_units( 1, $currency ) ? (string) (int) round( $amount ) : number_format( (float) $amount, 2, '.', '' );
	}

	private static function link( array $o, $rel ) {
		foreach ( (array) ( $o['links'] ?? array() ) as $l ) {
			if ( $rel === ( $l['rel'] ?? '' ) ) {
				return (string) $l['href'];
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------- */

	public function start( array $payment, array $membership, array $price, $user, array $urls ) {
		$plan     = FW_Membership_Plans::get( $membership['plan_id'] );
		$currency = strtoupper( $payment['currency'] );
		$ctx      = array(
			'brand_name'          => substr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 0, 127 ),
			'shipping_preference' => 'NO_SHIPPING',
			'return_url'          => $urls['success'],
			'cancel_url'          => $urls['cancel'],
		);

		if ( ! FW_Membership_Payments::is_recurring( $price ) ) {
			$ctx['user_action'] = 'PAY_NOW';
			$o = $this->api( 'POST', 'v2/checkout/orders', array(
				'intent'              => 'CAPTURE',
				'purchase_units'      => array( array(
					'reference_id' => 'fw-mbr-' . $payment['id'],
					'custom_id'    => (string) $payment['id'],
					'description'  => substr( $plan ? $plan['name'] : __( 'Membership', 'fw' ), 0, 127 ),
					'amount'       => array( 'currency_code' => $currency, 'value' => self::value( $payment['amount'], $currency ) ),
				) ),
				'application_context' => $ctx,
			), 'fw-mbr-order-' . $payment['id'] );
			if ( is_wp_error( $o ) ) {
				return $o;
			}
			FW_Membership_Payments::update( $payment['id'], array( 'gateway_ref' => (string) $o['id'] ) );
			return array( 'redirect' => self::link( $o, 'approve' ) );
		}

		$pp_plan = $this->plan_for( $price, $plan, (array) ( $payment['meta']['quote'] ?? array() ), (string) $payment['coupon'] );
		if ( is_wp_error( $pp_plan ) ) {
			return $pp_plan;
		}
		$ctx['user_action'] = 'SUBSCRIBE_NOW';
		$s = $this->api( 'POST', 'v1/billing/subscriptions', array(
			'plan_id'             => $pp_plan,
			'custom_id'           => (string) $payment['id'],
			'subscriber'          => array( 'email_address' => $user->user_email, 'name' => array_filter( array( 'given_name' => (string) $user->first_name, 'surname' => (string) $user->last_name ) ) ),
			'application_context' => $ctx,
		), 'fw-mbr-sub-' . $payment['id'] );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		FW_Membership_Payments::update( $payment['id'], array( 'gateway_ref' => (string) $s['id'] ) );
		return array( 'redirect' => self::link( $s, 'approve' ) );
	}

	/**
	 * The PayPal plan for a price (+ trial, setup fee, tax and coupon), made on first use.
	 *
	 * @return string|WP_Error
	 */
	private function plan_for( array $price, $plan, array $q, $coupon_code ) {
		$mode     = $this->live() ? 'live' : 'sandbox';
		$currency = strtoupper( '' !== (string) $price['currency'] ? $price['currency'] : FW_Membership_Gateways::currency() );
		$coupon   = '' !== $coupon_code && class_exists( 'FW_Membership_Coupons' ) ? FW_Membership_Coupons::get_by_code( $coupon_code ) : null;
		$sig      = md5( wp_json_encode( array( $price['amount'], $price['billing'], $price['interval_count'], $price['trial_days'], $price['setup_fee'], $q['tax_rate'] ?? 0, ! empty( $q['tax_inclusive'] ), $coupon ? array( $coupon['type'], $coupon['amount'], $coupon['duration'], $coupon['months'] ) : null ) ) );
		$key      = 'paypal_plan_' . $mode . '_' . $sig;
		if ( ! empty( $price['gateway_refs'][ $key ] ) ) {
			return (string) $price['gateway_refs'][ $key ];
		}
		$pkey    = 'paypal_product_' . $mode;
		$product = (string) ( $price['gateway_refs'][ $pkey ] ?? '' );
		if ( '' === $product ) {
			$p = $this->api( 'POST', 'v1/catalogs/products', array( 'name' => substr( $plan ? $plan['name'] : __( 'Membership', 'fw' ), 0, 127 ), 'type' => 'SERVICE', 'category' => 'SOFTWARE' ), 'fw-mbr-product-' . $price['id'] . '-' . $mode );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			$product = (string) $p['id'];
			FW_Membership_Plans::set_price_ref( $price['id'], $pkey, $product );
		}
		$unit   = array( 'day' => 'DAY', 'week' => 'WEEK', 'month' => 'MONTH', 'year' => 'YEAR' );
		$cycles = array();
		$seq    = 1;
		if ( (int) $price['trial_days'] > 0 ) {
			$cycles[] = array( 'frequency' => array( 'interval_unit' => 'DAY', 'interval_count' => min( 365, (int) $price['trial_days'] ) ), 'tenure_type' => 'TRIAL', 'sequence' => $seq++, 'total_cycles' => 1, 'pricing_scheme' => array( 'fixed_price' => array( 'value' => self::value( 0, $currency ), 'currency_code' => $currency ) ) );
		}
		$regular = (float) $price['amount'];
		if ( $coupon && 'forever' === $coupon['duration'] ) {
			$regular -= FW_Membership_Coupons::discount( $coupon, $regular );
		} elseif ( $coupon ) {
			// A discount for the first payment(s): a discounted trial cycle before the regular one.
			$months = 'once' === $coupon['duration'] ? 0 : (int) $coupon['months'];
			$per    = array( 'day' => 1 / 30, 'week' => 7 / 30, 'month' => 1, 'year' => 12 );
			$n      = 'once' === $coupon['duration'] ? 1 : max( 1, (int) ceil( $months / ( ( $per[ $price['billing'] ] ?? 1 ) * max( 1, (int) $price['interval_count'] ) ) ) );
			$cycles[] = array( 'frequency' => array( 'interval_unit' => $unit[ $price['billing'] ], 'interval_count' => max( 1, (int) $price['interval_count'] ) ), 'tenure_type' => 'TRIAL', 'sequence' => $seq++, 'total_cycles' => $n, 'pricing_scheme' => array( 'fixed_price' => array( 'value' => self::value( $regular - FW_Membership_Coupons::discount( $coupon, $regular ), $currency ), 'currency_code' => $currency ) ) );
		}
		$cycles[] = array( 'frequency' => array( 'interval_unit' => $unit[ $price['billing'] ], 'interval_count' => max( 1, (int) $price['interval_count'] ) ), 'tenure_type' => 'REGULAR', 'sequence' => $seq, 'total_cycles' => 0, 'pricing_scheme' => array( 'fixed_price' => array( 'value' => self::value( max( 0, $regular ), $currency ), 'currency_code' => $currency ) ) );
		$body = array(
			'product_id'          => $product,
			'name'                => substr( ( $plan ? $plan['name'] : __( 'Membership', 'fw' ) ) . ' — ' . FW_Membership_Plans::price_label( $price ), 0, 127 ),
			'billing_cycles'      => $cycles,
			'payment_preferences' => array( 'auto_bill_outstanding' => true, 'payment_failure_threshold' => 3, 'setup_fee_failure_action' => 'CANCEL' ),
		);
		if ( (float) $price['setup_fee'] > 0 ) {
			$body['payment_preferences']['setup_fee'] = array( 'value' => self::value( $price['setup_fee'], $currency ), 'currency_code' => $currency );
		}
		if ( ! empty( $q['tax_rate'] ) ) {
			$body['taxes'] = array( 'percentage' => (string) $q['tax_rate'], 'inclusive' => ! empty( $q['tax_inclusive'] ) );
		}
		$r = $this->api( 'POST', 'v1/billing/plans', $body, 'fw-mbr-plan-' . $price['id'] . '-' . $sig );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		FW_Membership_Plans::set_price_ref( $price['id'], $key, (string) $r['id'] );
		return (string) $r['id'];
	}

	/* ---------------------------------------------------------------------- */

	public function confirm_return( array $payment ) {
		if ( 'pending' !== $payment['status'] || '' === (string) $payment['gateway_ref'] ) {
			return;
		}
		if ( 0 === strpos( $payment['gateway_ref'], 'I-' ) ) {
			$this->apply_subscription( $payment['gateway_ref'] );
		} else {
			$this->capture( $payment['gateway_ref'] );
		}
	}

	/**
	 * Capture an approved order and finish the payment.
	 *
	 * @param string $order_id
	 */
	private function capture( $order_id ) {
		$p = FW_Membership_Payments::find_by_ref( 'paypal', $order_id );
		if ( ! $p || 'pending' !== $p['status'] ) {
			return;
		}
		$o = $this->api( 'POST', 'v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture', array(), 'fw-mbr-capture-' . $p['id'] );
		if ( is_wp_error( $o ) && 422 === (int) ( $o->get_error_data()['status'] ?? 0 ) ) {
			$o = $this->api( 'GET', 'v2/checkout/orders/' . rawurlencode( $order_id ) ); // already captured
		}
		if ( is_wp_error( $o ) || 'COMPLETED' !== ( $o['status'] ?? '' ) ) {
			return;
		}
		$cap = (array) ( $o['purchase_units'][0]['payments']['captures'][0] ?? array() );
		FW_Membership_Payments_Flow::paid( $p['id'], array( 'meta' => array( 'capture_id' => (string) ( $cap['id'] ?? '' ) ) ) );
	}

	/**
	 * Start the membership for an approved subscription.
	 *
	 * @param string $sub_id
	 */
	private function apply_subscription( $sub_id ) {
		$s = $this->api( 'GET', 'v1/billing/subscriptions/' . rawurlencode( $sub_id ) );
		if ( is_wp_error( $s ) || ! in_array( $s['status'] ?? '', array( 'ACTIVE' ), true ) ) {
			return;
		}
		$pid = (int) ( $s['custom_id'] ?? 0 );
		$p   = $pid ? FW_Membership_Payments::get( $pid ) : FW_Membership_Payments::find_by_ref( 'paypal', $sub_id );
		if ( ! $p || 'pending' !== $p['status'] ) {
			return;
		}
		$next  = strtotime( (string) ( $s['billing_info']['next_billing_time'] ?? '' ) );
		$trial = false;
		foreach ( (array) ( $s['billing_info']['cycle_executions'] ?? array() ) as $c ) {
			if ( 'TRIAL' === ( $c['tenure_type'] ?? '' ) && (int) ( $c['cycles_completed'] ?? 0 ) < (int) ( $c['total_cycles'] ?? 0 ) && 0.0 === (float) ( $s['billing_info']['last_payment']['amount']['value'] ?? 0 ) ) {
				$trial = true;
			}
		}
		FW_Membership_Payments_Flow::paid( $p['id'], array(
			'subscription_id' => $sub_id,
			'status'          => $trial ? 'trialing' : 'active',
			'period_end'      => $next ? $next : 0,
			'trial_end'       => $trial && $next ? $next : 0,
			'meta'            => array( 'subscription' => $sub_id ),
		) );
	}

	public function webhook( WP_REST_Request $request ) {
		$event = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $event ) || ! $this->verify( $request, $event ) ) {
			return new WP_Error( 'fw_mbr_signature', 'Invalid signature.', array( 'status' => 400 ) );
		}
		$r    = (array) ( $event['resource'] ?? array() );
		$type = (string) ( $event['event_type'] ?? '' );
		switch ( $type ) {
			case 'CHECKOUT.ORDER.APPROVED':
				$this->capture( (string) ( $r['id'] ?? '' ) );
				break;

			case 'PAYMENT.CAPTURE.COMPLETED':
				$pid = (int) ( $r['custom_id'] ?? 0 );
				$p   = $pid ? FW_Membership_Payments::get( $pid ) : null;
				if ( $p && 'paypal' === $p['gateway'] && 'pending' === $p['status'] ) {
					FW_Membership_Payments_Flow::paid( $pid, array( 'meta' => array( 'capture_id' => (string) ( $r['id'] ?? '' ) ) ) );
				}
				break;

			case 'BILLING.SUBSCRIPTION.ACTIVATED':
				$this->apply_subscription( (string) ( $r['id'] ?? '' ) );
				break;

			case 'PAYMENT.SALE.COMPLETED':
				$sub = (string) ( $r['billing_agreement_id'] ?? '' );
				$m   = '' !== $sub ? FW_Membership_Memberships::find_by_subscription( $sub ) : null;
				if ( ! $m ) {
					$this->apply_subscription( $sub ); // the first payment arrived before the activation
					break;
				}
				$first = FW_Membership_Payments::query( array( 'membership_id' => $m['id'], 'type' => 'payment', 'per_page' => 1 ) );
				if ( $first && 'paid' === $first[0]['status'] && $first[0]['amount'] > 0 && empty( $first[0]['meta']['sale_id'] ) ) {
					// The sale that paid for the start (recorded at activation): keep its id for refunds.
					FW_Membership_Payments::update( $first[0]['id'], array( 'meta' => array_merge( $first[0]['meta'], array( 'sale_id' => (string) $r['id'] ) ) ) );
					break;
				}
				$s    = $this->api( 'GET', 'v1/billing/subscriptions/' . rawurlencode( $sub ) );
				$next = is_wp_error( $s ) ? 0 : (int) strtotime( (string) ( $s['billing_info']['next_billing_time'] ?? '' ) );
				FW_Membership_Payments_Flow::renewed( $m, (string) $r['id'], (float) ( $r['amount']['total'] ?? 0 ), (string) ( $r['amount']['currency'] ?? '' ), $next ? $next : time() + MONTH_IN_SECONDS, array( 'sale_id' => (string) $r['id'] ) );
				break;

			case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
				$m = FW_Membership_Memberships::find_by_subscription( (string) ( $r['id'] ?? '' ) );
				if ( $m ) {
					FW_Membership_Payments_Flow::renewal_failed( $m, 'fail-' . (string) $r['id'] . '-' . gmdate( 'Ymd' ), (float) ( $r['billing_info']['outstanding_balance']['value'] ?? 0 ), (string) ( $r['billing_info']['outstanding_balance']['currency_code'] ?? '' ) );
				}
				break;

			case 'BILLING.SUBSCRIPTION.CANCELLED':
				$m = FW_Membership_Memberships::find_by_subscription( (string) ( $r['id'] ?? '' ) );
				if ( $m ) {
					// Billing stopped; access runs to the end of what was paid.
					$end = $m['renews_at'] ? strtotime( $m['renews_at'] ) : 0;
					if ( $end > current_time( 'timestamp' ) ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
						FW_Membership_Payments_Flow::cancel_scheduled( $m, (int) get_gmt_from_date( $m['renews_at'], 'U' ) );
					} else {
						FW_Membership_Payments_Flow::ended( $m );
					}
				}
				break;

			case 'BILLING.SUBSCRIPTION.EXPIRED':
			case 'BILLING.SUBSCRIPTION.SUSPENDED':
				$m = FW_Membership_Memberships::find_by_subscription( (string) ( $r['id'] ?? '' ) );
				if ( $m && ! ( 'paused' === $m['status'] && 'BILLING.SUBSCRIPTION.SUSPENDED' === $type ) ) {
					FW_Membership_Payments_Flow::ended( $m );
				}
				break;

			case 'PAYMENT.CAPTURE.REFUNDED':
			case 'PAYMENT.SALE.REFUNDED':
				$ref = 'PAYMENT.SALE.REFUNDED' === $type ? (string) ( $r['sale_id'] ?? '' ) : (string) self::up( $r );
				$pid = $ref ? self::payment_by_meta( 'PAYMENT.SALE.REFUNDED' === $type ? 'sale_id' : 'capture_id', $ref ) : 0;
				if ( $pid ) {
					$p = FW_Membership_Payments::get( $pid );
					FW_Membership_Payments_Flow::refunded( $pid, 'payment' === $p['type'] );
				}
				break;
		}
		return rest_ensure_response( array( 'received' => true ) );
	}

	/** The capture a refund belongs to (from its "up" link). */
	private static function up( array $r ) {
		foreach ( (array) ( $r['links'] ?? array() ) as $l ) {
			if ( 'up' === ( $l['rel'] ?? '' ) && preg_match( '#/captures/([^/]+)$#', (string) $l['href'], $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	private static function payment_by_meta( $key, $value ) {
		global $wpdb;
		$t = FW_Membership_Installer::table( 'payments' );
		$like = '%' . $wpdb->esc_like( '"' . $key . '":"' . $value . '"' ) . '%';
		$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE gateway = 'paypal' AND meta LIKE %s ORDER BY id DESC LIMIT 1", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $id && 'sale_id' === $key ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE gateway = 'paypal' AND gateway_ref = %s ORDER BY id DESC LIMIT 1", $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $id;
	}

	/**
	 * Ask PayPal whether a webhook really came from it.
	 *
	 * @param WP_REST_Request $request
	 * @param array           $event
	 *
	 * @return bool
	 */
	private function verify( WP_REST_Request $request, array $event ) {
		$id = trim( (string) $this->setting( 'webhook_id' ) );
		if ( '' === $id ) {
			return false;
		}
		$r = $this->api( 'POST', 'v1/notifications/verify-webhook-signature', array(
			'auth_algo'         => (string) $request->get_header( 'paypal_auth_algo' ),
			'cert_url'          => (string) $request->get_header( 'paypal_cert_url' ),
			'transmission_id'   => (string) $request->get_header( 'paypal_transmission_id' ),
			'transmission_sig'  => (string) $request->get_header( 'paypal_transmission_sig' ),
			'transmission_time' => (string) $request->get_header( 'paypal_transmission_time' ),
			'webhook_id'        => $id,
			'webhook_event'     => $event,
		) );
		return ! is_wp_error( $r ) && 'SUCCESS' === ( $r['verification_status'] ?? '' );
	}

	public function cancel( array $membership, $now = false ) {
		$sub = (string) $membership['gateway_subscription_id'];
		if ( '' === $sub ) {
			return true;
		}
		// PayPal stops billing at once either way; access still runs to the paid end date.
		$r = $this->api( 'POST', 'v1/billing/subscriptions/' . rawurlencode( $sub ) . '/cancel', array( 'reason' => $now ? 'Ended by the site' : 'Cancelled by the member' ) );
		if ( is_wp_error( $r ) && 422 === (int) ( $r->get_error_data()['status'] ?? 0 ) ) {
			return true; // already cancelled
		}
		return is_wp_error( $r ) ? $r : true;
	}

	public function pause( array $membership, $until ) {
		$sub = (string) $membership['gateway_subscription_id'];
		if ( '' === $sub ) {
			return true;
		}
		// PayPal has no resume date: the Retention module reactivates it on the day.
		$r = $this->api( 'POST', 'v1/billing/subscriptions/' . rawurlencode( $sub ) . '/suspend', array( 'reason' => 'Paused by the member' ) );
		return is_wp_error( $r ) ? $r : true;
	}

	public function unpause( array $membership ) {
		$sub = (string) $membership['gateway_subscription_id'];
		if ( '' === $sub ) {
			return true;
		}
		$r = $this->api( 'POST', 'v1/billing/subscriptions/' . rawurlencode( $sub ) . '/activate', array( 'reason' => 'Pause ended' ) );
		return is_wp_error( $r ) ? $r : true;
	}

	public function refund( array $payment ) {
		$currency = strtoupper( $payment['currency'] );
		if ( ! empty( $payment['meta']['capture_id'] ) ) {
			$r = $this->api( 'POST', 'v2/payments/captures/' . rawurlencode( $payment['meta']['capture_id'] ) . '/refund', array(), 'fw-mbr-refund-' . $payment['id'] );
		} elseif ( ! empty( $payment['meta']['sale_id'] ) ) {
			$r = $this->api( 'POST', 'v1/payments/sale/' . rawurlencode( $payment['meta']['sale_id'] ) . '/refund', array( 'amount' => array( 'total' => self::value( $payment['amount'], $currency ), 'currency' => $currency ) ), 'fw-mbr-refund-' . $payment['id'] );
		} else {
			return new WP_Error( 'fw_mbr_paypal_refund', __( 'No PayPal payment found to refund. Refund it in PayPal, then mark it refunded here.', 'fw' ) );
		}
		return is_wp_error( $r ) ? $r : true;
	}

	public function manage_url( $user, $return_url ) {
		return $this->live() ? 'https://www.paypal.com/myaccount/autopay/' : 'https://www.sandbox.paypal.com/myaccount/autopay/';
	}
}
