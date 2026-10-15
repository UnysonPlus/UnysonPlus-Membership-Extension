<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The payment gateway layer.
 *
 * One interface (FW_Membership_Gateway) for every way to pay, so a new gateway is an
 * add-on that registers on `unysonplus_membership_gateways`. Gateways never change a
 * membership themselves: they report what happened to FW_Membership_Payments_Flow
 * (paid, renewed, failed, cancelled, refunded), which goes through the service like
 * everything else, so history, events and emails stay whole.
 *
 * Settings for all gateways live in one option, `fw_ext_membership_payments`.
 */
abstract class FW_Membership_Gateway {

	/** @return string Machine id: stripe, offline, … */
	abstract public function id();

	/** @return string Name for admins. */
	abstract public function title();

	/** @return string What the buyer sees on the checkout. */
	public function checkout_label() {
		return $this->title();
	}

	/** @return string Short explanation under the label on the checkout. */
	public function checkout_description() {
		return '';
	}

	/**
	 * Settings fields for Membership → Payments → Settings.
	 *
	 * @return array key => { label, type: text|password|textarea|checkbox|select, desc?, choices? }
	 */
	public function settings_fields() {
		return array();
	}

	/** @return bool Configured well enough to take payments. */
	abstract public function is_ready();

	/** @return bool Can bill a price that renews (month, year…) automatically. */
	public function supports_recurring() {
		return false;
	}

	/**
	 * Start paying for a membership. Called with the membership and payment rows already
	 * created (both pending).
	 *
	 * @param array   $payment    The payment row.
	 * @param array   $membership The membership row.
	 * @param array   $price      The price row.
	 * @param WP_User $user
	 * @param array   $urls       { success, cancel } back to the checkout page.
	 *
	 * @return array|WP_Error { redirect?: url } or { done?: true, message?: string }
	 */
	abstract public function start( array $payment, array $membership, array $price, $user, array $urls );

	/**
	 * Stop billing: at the end of the paid period (access continues to then), or now.
	 *
	 * @param array $membership
	 * @param bool  $now
	 *
	 * @return true|WP_Error
	 */
	public function cancel( array $membership, $now = false ) {
		return true;
	}

	/** @return bool Can move a running subscription to another price (plan changes). */
	public function supports_switch() {
		return false;
	}

	/**
	 * Move a running subscription to another recurring price.
	 *
	 * @param array  $membership
	 * @param array  $price
	 * @param string $how prorate (charge the difference now) | none (new price from the next renewal)
	 *
	 * @return true|WP_Error
	 */
	public function switch_price( array $membership, array $price, $how ) {
		return new WP_Error( 'fw_mbr_no_switch', __( 'This payment method cannot change plans. Cancel and join the new plan instead.', 'fw' ) );
	}

	/**
	 * Stop billing until a date, then carry on by itself where the service can.
	 *
	 * @param array $membership
	 * @param int   $until unix
	 *
	 * @return true|WP_Error
	 */
	public function pause( array $membership, $until ) {
		return new WP_Error( 'fw_mbr_no_pause', __( 'This payment method cannot pause a subscription.', 'fw' ) );
	}

	/**
	 * End a pause early (or on its date when the service does not resume by itself).
	 *
	 * @param array $membership
	 *
	 * @return true|WP_Error
	 */
	public function unpause( array $membership ) {
		return true;
	}

	/**
	 * Take a percentage off a running subscription for some months (a retention offer).
	 *
	 * @param array $membership
	 * @param int   $percent
	 * @param int   $months
	 *
	 * @return true|WP_Error
	 */
	public function discount( array $membership, $percent, $months ) {
		return new WP_Error( 'fw_mbr_no_discount', __( 'This payment method cannot discount a running subscription.', 'fw' ) );
	}

	/**
	 * Undo a cancel-at-period-end (the subscription continues).
	 *
	 * @param array $membership
	 *
	 * @return true|WP_Error
	 */
	public function resume( array $membership ) {
		return new WP_Error( 'fw_mbr_no_resume', __( 'This payment method cannot resume a subscription.', 'fw' ) );
	}

	/**
	 * Refund a payment at the payment service.
	 *
	 * @param array $payment
	 *
	 * @return true|WP_Error
	 */
	/** @return bool Whether refund() returns the money through the payment service. */
	public function refunds_online() {
		return ( new ReflectionMethod( $this, 'refund' ) )->getDeclaringClass()->getName() !== __CLASS__;
	}

	public function refund( array $payment ) {
		return new WP_Error( 'fw_mbr_no_refund', __( 'Refunds for this payment method are made outside the site. Mark it refunded here once done.', 'fw' ) );
	}

	/**
	 * A link where the member manages their payment method, or ''.
	 *
	 * @param WP_User $user
	 * @param string  $return_url
	 *
	 * @return string|WP_Error
	 */
	public function manage_url( $user, $return_url ) {
		return '';
	}

	/**
	 * Notifications from the payment service (POST /wp-json/fw-membership/v1/webhooks/{id}).
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function webhook( WP_REST_Request $request ) {
		return new WP_Error( 'fw_mbr_no_webhook', 'This gateway has no webhook.', array( 'status' => 404 ) );
	}

	/**
	 * The buyer came back to the checkout page: confirm with the payment service
	 * (webhooks can be late), then the flow finalizes. Optional.
	 *
	 * @param array $payment
	 */
	public function confirm_return( array $payment ) {
	}

	/**
	 * This gateway's saved settings.
	 *
	 * @param string|null $key
	 *
	 * @return mixed
	 */
	public function setting( $key = null ) {
		$all  = FW_Membership_Gateways::settings();
		$mine = (array) ( $all['gateways'][ $this->id() ] ?? array() );
		return null === $key ? $mine : ( $mine[ $key ] ?? '' );
	}

	/** @return bool Switched on in the settings. */
	public function is_enabled() {
		return ! empty( $this->setting( 'enabled' ) );
	}
}

/**
 * Registry + settings.
 */
class FW_Membership_Gateways {

	const OPTION = 'fw_ext_membership_payments';

	/** @var FW_Membership_Gateway[]|null */
	private static $all = null;

	/**
	 * @return FW_Membership_Gateway[] id => gateway
	 */
	public static function all() {
		if ( null === self::$all ) {
			$list = array( new FW_Membership_Gateway_Stripe(), new FW_Membership_Gateway_PayPal(), new FW_Membership_Gateway_Offline() );
			/**
			 * Register payment gateways: append FW_Membership_Gateway instances.
			 */
			$list      = (array) apply_filters( 'unysonplus_membership_gateways', $list );
			self::$all = array();
			foreach ( $list as $g ) {
				if ( $g instanceof FW_Membership_Gateway && preg_match( '/^[a-z0-9_]+$/', $g->id() ) ) {
					self::$all[ $g->id() ] = $g;
				}
			}
		}
		return self::$all;
	}

	/**
	 * @param string $id
	 *
	 * @return FW_Membership_Gateway|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return $all[ (string) $id ] ?? null;
	}

	/**
	 * Gateways the buyer can choose for a price.
	 *
	 * @param array $price
	 *
	 * @return FW_Membership_Gateway[]
	 */
	public static function available_for( array $price ) {
		$recurring = FW_Membership_Payments::is_recurring( $price );
		return array_filter( self::all(), function ( $g ) use ( $recurring ) {
			return $g->is_enabled() && $g->is_ready() && ( ! $recurring || $g->supports_recurring() );
		} );
	}

	/** @return array { currency, checkout_page, terms_url, proration: prorate|next, gateways: id => settings } */
	public static function settings() {
		return array_merge(
			array( 'currency' => 'USD', 'checkout_page' => 0, 'terms_url' => '', 'proration' => 'prorate', 'gateways' => array() ),
			(array) get_option( self::OPTION, array() )
		);
	}

	/**
	 * @param array $values
	 */
	public static function save_settings( array $values ) {
		$out = array(
			'currency'      => strtoupper( substr( preg_replace( '/[^a-z]/i', '', (string) ( $values['currency'] ?? 'USD' ) ), 0, 3 ) ),
			'checkout_page' => max( 0, (int) ( $values['checkout_page'] ?? 0 ) ),
			'terms_url'     => esc_url_raw( (string) ( $values['terms_url'] ?? '' ) ),
			'proration'     => 'next' === ( $values['proration'] ?? '' ) ? 'next' : 'prorate',
			'invoice'       => ! isset( $values['invoice'] ) ? (array) ( self::settings()['invoice'] ?? array() ) : array(
				'prefix'   => substr( preg_replace( '/[^A-Za-z0-9_\/-]/', '', (string) ( $values['invoice']['prefix'] ?? 'INV-' ) ), 0, 20 ),
				'business' => sanitize_text_field( (string) ( $values['invoice']['business'] ?? '' ) ),
				'address'  => sanitize_textarea_field( (string) ( $values['invoice']['address'] ?? '' ) ),
				'tax_id'   => sanitize_text_field( (string) ( $values['invoice']['tax_id'] ?? '' ) ),
				'footer'   => sanitize_textarea_field( (string) ( $values['invoice']['footer'] ?? '' ) ),
			),
			'tax'           => (array) ( self::settings()['tax'] ?? array() ),
			'gateways'      => array(),
		);
		$old = self::settings();
		/** Settings a module keeps in this option (the Tax module stores `tax`). */
		$out = (array) apply_filters( 'unysonplus_membership_save_payment_settings', $out, $values, $old );
		foreach ( self::all() as $id => $g ) {
			$in   = (array) ( $values['gateways'][ $id ] ?? array() );
			$prev = (array) ( $old['gateways'][ $id ] ?? array() );
			$row  = array( 'enabled' => ! empty( $in['enabled'] ) ? 1 : 0 );
			foreach ( $g->settings_fields() as $key => $f ) {
				$v = $in[ $key ] ?? '';
				if ( 'password' === $f['type'] && '' === trim( (string) $v ) && isset( $prev[ $key ] ) ) {
					$row[ $key ] = $prev[ $key ]; // a blank secret field keeps the stored secret
					continue;
				}
				switch ( $f['type'] ) {
					case 'checkbox':
						$row[ $key ] = empty( $v ) ? 0 : 1;
						break;
					case 'textarea':
						$row[ $key ] = sanitize_textarea_field( (string) $v );
						break;
					case 'select':
						$row[ $key ] = isset( $f['choices'][ $v ] ) ? (string) $v : (string) array_key_first( $f['choices'] );
						break;
					default:
						$row[ $key ] = trim( sanitize_text_field( (string) $v ) );
				}
			}
			$out['gateways'][ $id ] = $row;
		}
		update_option( self::OPTION, $out, false );
	}

	/** @return string The default currency (3 letters). */
	public static function currency() {
		return (string) self::settings()['currency'];
	}

	/**
	 * @param array $args plan, price
	 *
	 * @return string The checkout page address (with the plan and price), or ''.
	 */
	public static function checkout_url( array $args = array() ) {
		$page = (int) self::settings()['checkout_page'];
		if ( ! $page || 'publish' !== get_post_status( $page ) ) {
			return '';
		}
		return add_query_arg( array_filter( $args ), get_permalink( $page ) );
	}
}
