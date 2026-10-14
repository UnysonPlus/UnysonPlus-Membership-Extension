<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Module switches.
 *
 * The core is always on. Every other module lives in modules/<slug>/ and is only
 * loaded (its loader file required) when it is switched on, so a module that is off
 * loads no code and adds no menus. A module whose loader file does not exist yet is
 * listed as "Coming soon" and cannot be switched on.
 *
 * Some modules are automatic: `crm` is on whenever the CRM extension is active
 * (there is nothing to switch), and `woocommerce` needs WooCommerce.
 */
class FW_Membership_Modules {

	const OPTION = 'fw_ext_membership_modules';

	/**
	 * @return array slug => { label, description, icon, phase, requires? (callable), auto? }
	 */
	public static function registry() {
		$modules = array(
			'payments'    => array( __( 'Payments', 'fw' ), __( 'Sell plans with your own payment accounts: cards and wallets, PayPal and bank transfer, a checkout and a pricing table. No transaction fee from Unyson+.', 'fw' ), 'dashicons-money-alt', 3 ),
			'coupons'     => array( __( 'Coupons', 'fw' ), __( 'Percentage or fixed discounts for the first payment or for ever, with limits, expiry dates and bulk codes.', 'fw' ), 'dashicons-tickets-alt', 3 ),
			'tax'         => array( __( 'Tax', 'fw' ), __( 'Tax rates by country and region, EU VAT with number validation, or automatic tax by the payment service.', 'fw' ), 'dashicons-bank', 3 ),
			'crm'         => array( __( 'CRM', 'fw' ), __( 'Every member becomes a CRM contact, plans mirror to tags, access rules can use CRM tags and lists, and membership events show on the timeline.', 'fw' ), 'dashicons-id-alt', 4 ),
			'drip'        => array( __( 'Drip content', 'fw' ), __( 'Open content a number of days after someone joins, on a date, or in a sequence.', 'fw' ), 'dashicons-clock', 5 ),
			'paywall'     => array( __( 'Metered paywall', 'fw' ), __( 'Let visitors read a few items for free before asking them to register or subscribe.', 'fw' ), 'dashicons-visibility', 5 ),
			'files'       => array( __( 'Protected downloads', 'fw' ), __( 'Files served only to people with access, with download limits and a log.', 'fw' ), 'dashicons-download', 5 ),
			'retention'   => array( __( 'Retention', 'fw' ), __( 'Recover failed payments, offer a pause instead of a cancellation, ask why people leave, and win them back.', 'fw' ), 'dashicons-heart', 6 ),
			'gifts'       => array( __( 'Gift memberships', 'fw' ), __( 'Buy a plan for someone else, redeemed with a code or a link.', 'fw' ), 'dashicons-buddicons-community', 6 ),
			'groups'      => array( __( 'Groups and teams', 'fw' ), __( 'One buyer pays for several seats and invites people by email, link or email domain.', 'fw' ), 'dashicons-groups', 7 ),
			'directory'   => array( __( 'Member directory', 'fw' ), __( 'A searchable directory and public profiles, with privacy for each field.', 'fw' ), 'dashicons-id', 7 ),
			'cards'       => array( __( 'Membership cards', 'fw' ), __( 'Printable or digital cards with a QR code for check-in.', 'fw' ), 'dashicons-index-card', 7 ),
			'woocommerce' => array( __( 'WooCommerce', 'fw' ), __( 'Products that grant plans, member prices and free shipping, and members-only products.', 'fw' ), 'dashicons-cart', 8 ),
			'analytics'   => array( __( 'Reports', 'fw' ), __( 'Monthly recurring revenue, churn, lifetime value, trial conversion and cohorts.', 'fw' ), 'dashicons-chart-area', 9 ),
			'security'    => array( __( 'Account security', 'fw' ), __( 'Limit simultaneous sessions, one-click login links that work once, passkeys and a login log.', 'fw' ), 'dashicons-shield', 10 ),
			'importers'   => array( __( 'Importers', 'fw' ), __( 'Bring in members, plans and live subscriptions from a CSV file or another membership plugin.', 'fw' ), 'dashicons-database-import', 10 ),
			'courses'     => array( __( 'Courses', 'fw' ), __( 'Courses with lessons, progress, quizzes and certificates on the same access rules.', 'fw' ), 'dashicons-welcome-learn-more', 11 ),
		);

		$out = array();
		foreach ( $modules as $slug => $m ) {
			$out[ $slug ] = array(
				'label'       => $m[0],
				'description' => $m[1],
				'icon'        => $m[2],
				'phase'       => $m[3],
			);
		}
		$out['crm']['auto']              = true;
		$out['crm']['requires']          = function () {
			return function_exists( 'fw_ext' ) && (bool) fw_ext( 'crm' );
		};
		$out['crm']['requires_text']     = __( 'Turns on by itself while the CRM extension is active.', 'fw' );
		// Modules that only make sense while Payments is on.
		foreach ( array( 'coupons', 'tax', 'gifts' ) as $needs_payments ) {
			$out[ $needs_payments ]['requires']      = function () {
				return in_array( 'payments', (array) get_option( FW_Membership_Modules::OPTION, array() ), true );
			};
			$out[ $needs_payments ]['requires_text'] = __( 'Needs the Payments module.', 'fw' );
		}
		$out['woocommerce']['requires']  = function () {
			return class_exists( 'WooCommerce' );
		};
		$out['woocommerce']['requires_text'] = __( 'Needs WooCommerce.', 'fw' );

		/** Filters the module registry, for add-on modules. */
		return (array) apply_filters( 'unysonplus_membership_modules', $out );
	}

	/**
	 * Is the module's code present? (A planned module has no loader yet.)
	 *
	 * @param string $slug
	 *
	 * @return bool
	 */
	public static function is_available( $slug ) {
		return file_exists( self::loader( $slug ) );
	}

	/**
	 * @param string $slug
	 *
	 * @return bool
	 */
	public static function is_on( $slug ) {
		$reg = self::registry();
		if ( ! isset( $reg[ $slug ] ) || ! self::is_available( $slug ) ) {
			return false;
		}
		if ( isset( $reg[ $slug ]['requires'] ) && ! call_user_func( $reg[ $slug ]['requires'] ) ) {
			return false;
		}
		if ( ! empty( $reg[ $slug ]['auto'] ) ) {
			return true;
		}
		return in_array( $slug, (array) get_option( self::OPTION, array() ), true );
	}

	/**
	 * @param string $slug
	 * @param bool   $on
	 *
	 * @return true|WP_Error
	 */
	public static function set( $slug, $on ) {
		$reg = self::registry();
		if ( ! isset( $reg[ $slug ] ) ) {
			return new WP_Error( 'fw_mbr_module', __( 'Unknown module.', 'fw' ) );
		}
		if ( $on && ! self::is_available( $slug ) ) {
			return new WP_Error( 'fw_mbr_module_soon', __( 'That module is not available yet.', 'fw' ) );
		}
		$active = array_values( array_diff( (array) get_option( self::OPTION, array() ), array( $slug ) ) );
		if ( $on ) {
			$active[] = $slug;
		}
		update_option( self::OPTION, $active, true );

		return true;
	}

	/** Load every module that is on. Called once from _init(). */
	public static function load() {
		foreach ( array_keys( self::registry() ) as $slug ) {
			if ( self::is_on( $slug ) ) {
				require_once self::loader( $slug );
			}
		}
	}

	private static function loader( $slug ) {
		$slug = sanitize_key( $slug );
		return dirname( dirname( __FILE__ ) ) . '/modules/' . $slug . '/' . $slug . '.php';
	}
}
