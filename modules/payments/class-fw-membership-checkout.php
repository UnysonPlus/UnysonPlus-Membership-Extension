<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The checkout: the Membership Checkout element's form posts here (handled before any
 * output), and the buyer returns here from the payment page.
 *
 *   POST  fw_mbr_checkout=1 + plan + price + gateway (+ email, names, password when
 *         logged out) → account (new or existing, logged in) → FW_Membership_Payments_Flow::start()
 *         → the gateway's payment page, or straight back with a result.
 *   GET   ?fw_mbr_payment=<id>&k=<key> → the gateway confirms the payment (webhooks can be
 *         late) and the element shows the outcome. The key is random per payment, so the
 *         id alone shows nothing.
 *
 * A logged-out buyer whose email already has an account is asked to log in first, never
 * attached to someone else's account.
 */
class FW_Membership_Checkout {

	const NONCE = 'fw_mbr_checkout';

	/** @var array|null The outcome to show on this request. */
	public static $outcome = null;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, '_action_handle' ), 2 );
	}

	/**
	 * The plan and price a checkout URL asks for.
	 *
	 * @return array [ plan|null, price|null ]
	 */
	public static function requested() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only selection.
		$plan_ref = isset( $_REQUEST['plan'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['plan'] ) ) : '';
		$price_id = isset( $_REQUEST['price'] ) ? (int) $_REQUEST['price'] : 0;
		// phpcs:enable
		$plan = ctype_digit( $plan_ref ) ? FW_Membership_Plans::get( (int) $plan_ref ) : FW_Membership_Plans::get_by_slug( sanitize_title( $plan_ref ) );
		if ( ! $plan || 'active' !== $plan['status'] ) {
			return array( null, null );
		}
		$price = null;
		foreach ( $plan['prices'] as $p ) {
			if ( ! $price_id || $p['id'] === $price_id ) {
				$price = $p;
				break;
			}
		}
		if ( ! $price && ! $plan['prices'] ) {
			$price = array( 'id' => 0, 'plan_id' => $plan['id'], 'label' => '', 'amount' => 0.0, 'currency' => FW_Membership_Gateways::currency(), 'billing' => 'free', 'interval_count' => 1, 'trial_days' => 0, 'setup_fee' => 0.0, 'status' => 'active' );
		}
		return array( $plan, $price );
	}

	/**
	 * The membership this checkout replaces (?replaces=<id>, a plan change), when the
	 * buyer owns it and its plan allows the move.
	 *
	 * @param array|null $plan The plan being bought.
	 *
	 * @return array|null
	 */
	public static function replacing( $plan ) {
		$id = isset( $_REQUEST['replaces'] ) ? (int) $_REQUEST['replaces'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $id || ! $plan || ! is_user_logged_in() ) {
			return null;
		}
		$m = FW_Membership_Memberships::get( $id );
		if ( ! $m || (int) $m['user_id'] !== get_current_user_id() || ! in_array( $m['status'], FW_Membership_Memberships::ACCESS_STATUSES, true ) ) {
			return null;
		}
		$from = FW_Membership_Plans::get( $m['plan_id'] );
		return $from && in_array( (int) $plan['id'], array_map( 'intval', (array) ( $from['settings']['switch_to'] ?? array() ) ), true ) ? $m : null;
	}

	/** @internal */
	public static function _action_handle() {
		// Returning from the payment page.
		// phpcs:disable WordPress.Security.NonceVerification -- the per-payment key is the credential.
		if ( isset( $_GET['fw_mbr_payment'], $_GET['k'] ) ) {
			$p = FW_Membership_Payments::get( (int) $_GET['fw_mbr_payment'] );
			if ( $p && hash_equals( (string) ( $p['meta']['key'] ?? '' ), sanitize_text_field( wp_unslash( $_GET['k'] ) ) ) ) {
				FW_Membership_Cache::no_cache();
				if ( empty( $_GET['fw_mbr_cancelled'] ) && 'pending' === $p['status'] ) {
					$g = FW_Membership_Gateways::get( $p['gateway'] );
					if ( $g ) {
						$g->confirm_return( $p );
						$p = FW_Membership_Payments::get( $p['id'] );
					}
				}
				self::$outcome = array(
					'type'    => ! empty( $_GET['fw_mbr_cancelled'] ) ? 'cancelled' : ( 'paid' === $p['status'] ? 'paid' : ( 'offline' === $p['gateway'] ? 'instructions' : 'processing' ) ),
					'payment' => $p,
				);
			}
			return;
		}
		// phpcs:enable
		if ( empty( $_POST['fw_mbr_checkout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( self::NONCE );
		FW_Membership_Cache::no_cache();
		$r = self::process();
		if ( is_wp_error( $r ) ) {
			self::$outcome = array( 'type' => 'error', 'message' => $r->get_error_message() );
			return;
		}
		if ( ! empty( $r['redirect'] ) ) {
			wp_redirect( $r['redirect'] ); // phpcs:ignore WordPress.Security.SafeRedirect -- the payment service's page.
			exit;
		}
		if ( ! empty( $r['payment_id'] ) ) {
			wp_safe_redirect( add_query_arg( array( 'fw_mbr_payment' => $r['payment_id'], 'k' => $r['key'] ), self::here() ) );
			exit;
		}
		// Free: done.
		wp_safe_redirect( add_query_arg( 'fw_mbr_notice', 'joined', FW_Membership_Settings::account_url() ) );
		exit;
	}

	/**
	 * @return array|WP_Error
	 */
	private static function process() {
		// phpcs:disable WordPress.Security.NonceVerification -- checked in _action_handle().
		list( $plan, $price ) = self::requested();
		if ( ! $plan || ! $price ) {
			return new WP_Error( 'fw_mbr_no_plan', __( 'Please choose a plan.', 'fw' ) );
		}
		if ( '' !== (string) ( FW_Membership_Gateways::settings()['terms_url'] ?? '' ) && empty( $_POST['terms'] ) ) {
			return new WP_Error( 'fw_mbr_terms', __( 'Please accept the terms to continue.', 'fw' ) );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$pass  = (string) wp_unslash( $_POST['password'] ?? '' );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'fw_mbr_email', __( 'Please enter a valid email address.', 'fw' ) );
			}
			if ( email_exists( $email ) ) {
				return new WP_Error( 'fw_mbr_exists', __( 'An account with this email already exists. Please log in first, then come back to this page.', 'fw' ) );
			}
			if ( strlen( $pass ) < 8 ) {
				return new WP_Error( 'fw_mbr_pass', __( 'Choose a password of at least 8 characters.', 'fw' ) );
			}
			$first   = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
			$last    = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
			$user_id = wp_insert_user( array(
				'user_login'   => FW_Membership_Checkout::username( $email ),
				'user_email'   => $email,
				'user_pass'    => $pass,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ) ? trim( $first . ' ' . $last ) : strstr( $email, '@', true ),
				'role'         => get_option( 'default_role', 'subscriber' ),
			) );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			update_user_meta( $user_id, '_fw_mbr_registered_via', 'checkout' );
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, true );
		}
		$gateway = sanitize_key( wp_unslash( $_POST['gateway'] ?? '' ) );
		// phpcs:enable
		$here = self::here();
		$old  = self::replacing( $plan );
		return FW_Membership_Payments_Flow::start( $user_id, $plan, $price, $gateway, array( 'success' => $here, 'cancel' => $here ), array_merge( self::buyer_args(), array( 'replaces' => $old ? $old['id'] : 0, 'checkout_submit' => true ) ) );
	}

	/**
	 * What the buyer entered that changes the price: a coupon, their country / region and
	 * VAT number (read by the Coupons and Tax modules through the quote).
	 *
	 * @return array
	 */
	public static function buyer_args() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only; the POST is nonce-checked before use.
		$out = array();
		foreach ( array( 'coupon', 'country', 'region', 'vat_number', 'gift' ) as $k ) {
			if ( isset( $_REQUEST[ $k ] ) && '' !== trim( (string) $_REQUEST[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( wp_unslash( $_REQUEST[ $k ] ) );
			}
		}
		// phpcs:enable
		return $out;
	}

	/** @return string The current page without checkout leftovers. */
	public static function here() {
		$url = ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return remove_query_arg( array( 'fw_mbr_payment', 'k', 'session_id', 'fw_mbr_cancelled', 'fw_mbr_notice' ), $url );
	}

	/**
	 * @param string $email
	 *
	 * @return string An unused username from the email's local part.
	 */
	public static function username( $email ) {
		$base = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base = strlen( $base ) >= 3 ? substr( $base, 0, 50 ) : 'member';
		$name = $base;
		$n    = 1;
		while ( username_exists( $name ) ) {
			$name = $base . ( ++$n );
		}
		return $name;
	}
}
