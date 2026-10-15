<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site-wide Membership settings (Membership → Settings), one option.
 */
class FW_Membership_Settings {

	const OPTION = 'fw_ext_membership_settings';

	/** @var array|null */
	private static $cache = null;

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'account_page'    => 0,   // page holding the Member Account element
			'login_page'      => 0,   // page holding the Member Login element (0: wp-login.php)
			'join_page'       => 0,   // page holding the Pricing Plans element (where prompts' Join button goes)
			'after_verify'    => '',  // where people land after confirming their email ('' = account page)
			'verify_days'     => 7,   // how long a confirmation link works
			'notify_admin'    => 1,   // email the site admin about sign-ups awaiting approval
			'admin_email'     => '',  // '' = the site admin email
			'member_cancel'   => 1,   // members may cancel from their account page
			'button_class'    => 'btn btn-primary', // the theme's button classes (Theme Settings presets)
			'button_class_2'  => 'btn btn-outline', // a second, quieter button
		);
	}

	/**
	 * @param string|null $key
	 *
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			self::$cache = array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
		}
		return null === $key ? self::$cache : ( self::$cache[ $key ] ?? null );
	}

	/**
	 * @param array $values
	 */
	public static function save( array $values ) {
		$d   = self::defaults();
		$out = array(
			'account_page' => max( 0, (int) ( $values['account_page'] ?? 0 ) ),
			'login_page'   => max( 0, (int) ( $values['login_page'] ?? 0 ) ),
			'join_page'    => max( 0, (int) ( $values['join_page'] ?? 0 ) ),
			'after_verify' => esc_url_raw( (string) ( $values['after_verify'] ?? '' ) ),
			'verify_days'  => max( 1, min( 30, (int) ( $values['verify_days'] ?? $d['verify_days'] ) ) ),
			'notify_admin' => empty( $values['notify_admin'] ) ? 0 : 1,
			'admin_email'  => sanitize_email( (string) ( $values['admin_email'] ?? '' ) ),
			'member_cancel' => empty( $values['member_cancel'] ) ? 0 : 1,
			'button_class'   => self::clean_classes( (string) ( $values['button_class'] ?? $d['button_class'] ), $d['button_class'] ),
			'button_class_2' => self::clean_classes( (string) ( $values['button_class_2'] ?? $d['button_class_2'] ), $d['button_class_2'] ),
		);
		update_option( self::OPTION, $out, false );
		self::$cache = null;
	}

	/**
	 * Where a prompt's Join button goes: the Join page when one is set, otherwise the
	 * checkout for the one plan that unlocks the content (when Payments is on and it has a
	 * price), otherwise nowhere ('').
	 *
	 * @param int[] $plan_ids The plans that would unlock it.
	 *
	 * @return string
	 */
	public static function join_url( array $plan_ids = array() ) {
		$page = (int) self::get( 'join_page' );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			return get_permalink( $page );
		}
		if ( 1 === count( $plan_ids ) && class_exists( 'FW_Membership_Gateways' ) && method_exists( 'FW_Membership_Gateways', 'checkout_url' ) ) {
			$plan = FW_Membership_Plans::get( (int) reset( $plan_ids ) );
			if ( $plan && ! empty( $plan['prices'] ) ) {
				return FW_Membership_Gateways::checkout_url( array( 'plan' => $plan['slug'] ) );
			}
		}
		return '';
	}

	/** @return string The member account page, or the WordPress profile. */
	public static function account_url() {
		$page = (int) self::get( 'account_page' );
		return $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : admin_url( 'profile.php' );
	}

	/**
	 * @param string $redirect Where to go after logging in.
	 *
	 * @return string
	 */
	public static function login_url( $redirect = '' ) {
		$page = (int) self::get( 'login_page' );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			return '' !== $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), get_permalink( $page ) ) : get_permalink( $page );
		}
		return wp_login_url( $redirect );
	}

	/**
	 * @param string $classes
	 * @param string $default
	 *
	 * @return string
	 */
	private static function clean_classes( $classes, $default ) {
		$out = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $classes ) ) ) ) );
		return '' !== $out ? $out : $default;
	}

	/**
	 * The theme's button classes for Membership's buttons, so they match the site's
	 * buttons (Theme Settings button presets); `fw-mbr-btn` carries our own fallback
	 * look for themes that do not style `.btn`.
	 *
	 * @param bool $secondary The quieter second button.
	 *
	 * @return string
	 */
	public static function button_class( $secondary = false ) {
		$key = $secondary ? 'button_class_2' : 'button_class';
		$cls = trim( (string) self::get( $key ) );
		$def = self::defaults()[ $key ];
		if ( '' === $cls || $def === $cls ) {
			// Left at the default: use the site's own presets when it has no preset of that name.
			$cls = self::preset_class( $secondary, $def );
		}
		return trim( $cls . ' fw-mbr-btn' );
	}

	/** @return array The site's button presets: class => name (from Theme Settings). */
	public static function button_presets() {
		$list = function_exists( 'fw_get_db_settings_option' ) ? fw_get_db_settings_option( 'button_colors' ) : null;
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $p ) {
			if ( ! empty( $p['color_name'] ) ) {
				$out[ 'btn btn-' . sanitize_title_with_dashes( $p['color_name'] ) ] = array(
					'name'   => (string) $p['color_name'],
					'filled' => '' !== (string) ( $p['states']['default']['bg_color']['custom'] ?? '' ) || '' !== (string) ( $p['states']['default']['bg_color']['predefined'] ?? '' ),
					'hint'   => strtolower( ( $p['slug'] ?? '' ) . ' ' . ( $p['role'] ?? '' ) . ' ' . $p['color_name'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * The preset to use for a default-set button: the default's own preset if the site has
	 * one, else a filled preset (main) or an outlined one (second), named like it first.
	 */
	private static function preset_class( $secondary, $default ) {
		$presets = self::button_presets();
		if ( ! $presets || isset( $presets[ $default ] ) ) {
			return $default;
		}
		$words = $secondary ? array( 'outline', 'secondary', 'ghost' ) : array( 'primary', 'fill', 'solid' );
		foreach ( array( true, false ) as $by_name ) {
			foreach ( $presets as $cls => $p ) {
				if ( $p['filled'] === $secondary ) {
					continue;
				}
				if ( ! $by_name || preg_match( '/\b(' . implode( '|', $words ) . ')\b/', $p['hint'] ) ) {
					return $cls;
				}
			}
		}
		return $default;
	}

	/** @return string */
	public static function admin_email() {
		$e = (string) self::get( 'admin_email' );
		return is_email( $e ) ? $e : (string) get_option( 'admin_email' );
	}
}
