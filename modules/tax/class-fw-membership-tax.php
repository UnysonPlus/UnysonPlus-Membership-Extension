<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Tax (Membership → Modules → Tax; settings under Payment settings → Tax).
 *
 *   mode            rates (your own table) | stripe (Stripe Tax works it out; card payments only)
 *   inclusive       prices already include tax (the tax is shown, the price stays)
 *   shop_country    where the business is (for EU reverse charge)
 *   rates           [ { country (ISO, or * for everywhere else), region, rate, label } ]
 *   eu_vat          ask EU buyers for a VAT number; a valid number from another EU
 *                   country is reverse-charged (no VAT)
 *
 * The rate is chosen by country + region, then country, then *. VAT numbers are checked
 * with the EU's VIES service (cached for a day); when VIES cannot be reached, VAT is
 * charged and the buyer is told why.
 */
class FW_Membership_Tax {

	public static function init() {
		add_filter( 'unysonplus_membership_quote', array( __CLASS__, '_quote' ), 20, 4 );
		add_filter( 'unysonplus_membership_stripe_session', array( __CLASS__, '_stripe_session' ), 20, 4 );
		add_filter( 'unysonplus_membership_save_payment_settings', array( __CLASS__, '_save' ), 10, 3 );
		add_action( 'unysonplus_membership_payment_settings_panels', array( __CLASS__, '_panel' ) );
		add_action( 'unysonplus_membership_checkout_price_fields', array( __CLASS__, '_fields' ), 10, 3 );
		add_action( 'unysonplus_membership_checkout_completed', array( __CLASS__, '_remember' ), 10, 4 );
	}

	/** @return array */
	public static function settings() {
		return array_merge( array(
			'mode'         => 'rates',
			'inclusive'    => 0,
			'shop_country' => '',
			'rates'        => array(),
			'eu_vat'       => 0,
		), (array) ( FW_Membership_Gateways::settings()['tax'] ?? array() ) );
	}

	/**
	 * The rate for a place.
	 *
	 * @param string $country
	 * @param string $region
	 *
	 * @return array|null { rate, label }
	 */
	public static function rate_for( $country, $region = '' ) {
		$country = strtoupper( (string) $country );
		$region  = strtoupper( trim( (string) $region ) );
		$best    = null;
		$score   = -1;
		foreach ( (array) self::settings()['rates'] as $r ) {
			$rc = strtoupper( (string) $r['country'] );
			$rr = strtoupper( trim( (string) ( $r['region'] ?? '' ) ) );
			if ( '*' !== $rc && $rc !== $country ) {
				continue;
			}
			if ( '' !== $rr && $rr !== $region ) {
				continue;
			}
			$s = ( '*' === $rc ? 0 : 2 ) + ( '' !== $rr ? 1 : 0 );
			if ( $s > $score ) {
				$best  = $r;
				$score = $s;
			}
		}
		return $best ? array( 'rate' => (float) $best['rate'], 'label' => '' !== (string) ( $best['label'] ?? '' ) ? $best['label'] : __( 'Tax', 'fw' ) ) : null;
	}

	/**
	 * Is this a valid EU VAT number? (VIES, cached for a day.)
	 *
	 * @param string $country ISO code of the buyer's country.
	 * @param string $number  With or without the country prefix.
	 *
	 * @return bool|null True / false, or null when VIES could not answer.
	 */
	public static function vat_valid( $country, $number ) {
		$cc     = strtoupper( (string) $country );
		$prefix = 'GR' === $cc ? 'EL' : $cc; // Greece uses EL in VAT numbers
		$num    = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $number ) );
		if ( 0 === strpos( $num, $prefix ) ) {
			$num = substr( $num, strlen( $prefix ) );
		}
		if ( '' === $num || ! FW_Membership_Countries::in_eu( $cc ) ) {
			return false;
		}
		$key    = 'fw_mbr_vat_' . md5( $prefix . $num );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}
		/** Short-circuit the VIES check (tests, or another validation service): return true / false / null. */
		$pre = apply_filters( 'unysonplus_membership_vat_check', null, $prefix, $num );
		if ( null === $pre ) {
			$res = wp_remote_get( 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/' . rawurlencode( $prefix ) . '/vat/' . rawurlencode( $num ), array( 'timeout' => 10 ) );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				return null;
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( ! is_array( $body ) || ! isset( $body['isValid'] ) ) {
				return null;
			}
			$pre = (bool) $body['isValid'];
		}
		set_transient( $key, $pre ? 'yes' : 'no', DAY_IN_SECONDS );
		return (bool) $pre;
	}

	/* ---------------------------------------------------------------------- *
	 * Quote
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _quote( $q, $plan, $price, $args ) {
		$s = self::settings();
		$q['tax_inclusive'] = ! empty( $s['inclusive'] );
		if ( 'stripe' === $s['mode'] ) {
			$q['tax_label'] = __( 'Tax is worked out on the payment page.', 'fw' );
			return $q;
		}
		$country = strtoupper( (string) ( $args['country'] ?? '' ) );
		if ( '' === $country && ! empty( $args['user_id'] ) ) {
			$country = (string) get_user_meta( (int) $args['user_id'], '_fw_mbr_country', true );
		}
		if ( '' === $country ) {
			if ( ! empty( $args['checkout_submit'] ) && $s['rates'] ) {
				$q['errors'][] = __( 'Please choose your country (it decides the tax), then update the price.', 'fw' );
			}
			return $q; // asked for at the checkout; no tax until we know where
		}
		$q['tax_country'] = $country;

		// EU reverse charge for a business in another EU country.
		if ( ! empty( $s['eu_vat'] ) && ! empty( $args['vat_number'] ) && FW_Membership_Countries::in_eu( $country ) && strtoupper( (string) $s['shop_country'] ) !== $country ) {
			$valid = self::vat_valid( $country, $args['vat_number'] );
			if ( true === $valid ) {
				$q['tax_label']      = __( 'Reverse charge: VAT to be accounted for by the buyer', 'fw' );
				$q['reverse_charge'] = true;
				return $q;
			}
			$q['tax_note'] = null === $valid ? __( 'The VAT number could not be checked just now, so VAT is charged.', 'fw' ) : __( 'That VAT number is not valid, so VAT is charged.', 'fw' );
		}

		$r = self::rate_for( $country, (string) ( $args['region'] ?? '' ) );
		if ( ! $r || $r['rate'] <= 0 ) {
			return $q;
		}
		$base            = max( 0.0, $q['due'] - (float) $q['discount'] );
		$q['tax_rate']   = $r['rate'];
		$q['tax_label']  = sprintf( '%s (%s%%)', $r['label'], rtrim( rtrim( number_format( $r['rate'], 3, '.', '' ), '0' ), '.' ) );
		$q['tax']        = $q['tax_inclusive'] ? round( $base - $base / ( 1 + $r['rate'] / 100 ), 2 ) : round( $base * $r['rate'] / 100, 2 );
		return $q;
	}

	/**
	 * Stripe: our rate as a Stripe tax rate on each line, or Stripe Tax.
	 *
	 * @internal
	 */
	public static function _stripe_session( $params, $payment, $membership, $price ) {
		$s = self::settings();
		$g = FW_Membership_Gateways::get( 'stripe' );
		if ( ! $g ) {
			return $params;
		}
		if ( 'stripe' === $s['mode'] ) {
			$params['automatic_tax']            = array( 'enabled' => 'true' );
			$params['billing_address_collection'] = 'required';
			$params['customer_update']          = array( 'address' => 'auto', 'name' => 'auto' );
			if ( ! empty( $s['eu_vat'] ) ) {
				$params['tax_id_collection'] = array( 'enabled' => 'true' );
			}
			return $params;
		}
		$q = (array) ( $payment['meta']['quote'] ?? array() );
		if ( empty( $q['tax_rate'] ) || (float) $q['tax_rate'] <= 0 ) {
			return $params;
		}
		$country = (string) ( $payment['meta']['tax_info']['country'] ?? '' );
		$mode    = 'live' === $g->setting( 'mode' ) ? 'live' : 'test';
		$sig     = implode( '|', array( $mode, (float) $q['tax_rate'], ! empty( $q['tax_inclusive'] ) ? 1 : 0, (string) $q['tax_label'], $country ) );
		$refs    = (array) get_option( 'fw_ext_membership_stripe_tax_rates', array() );
		if ( empty( $refs[ $sig ] ) ) {
			$r = $g->api( 'POST', 'tax_rates', array_filter( array(
				'display_name' => substr( preg_replace( '/\s*\(.*$/', '', (string) $q['tax_label'] ), 0, 50 ),
				'percentage'   => (float) $q['tax_rate'],
				'inclusive'    => ! empty( $q['tax_inclusive'] ) ? 'true' : 'false',
				'country'      => $country,
				'_idempotency' => 'fw-mbr-tax-' . md5( $sig ),
			) ) );
			if ( is_wp_error( $r ) ) {
				$params['_fw_error'] = $r->get_error_message();
				return $params;
			}
			$refs[ $sig ] = (string) $r['id'];
			update_option( 'fw_ext_membership_stripe_tax_rates', $refs, false );
		}
		foreach ( $params['line_items'] as $i => $unused ) {
			$params['line_items'][ $i ]['tax_rates'] = array( $refs[ $sig ] );
		}
		return $params;
	}

	/** @internal Remember the buyer's country for next time. */
	public static function _remember( $q, $user_id, $membership_id, $payment_id ) {
		$p = $payment_id ? FW_Membership_Payments::get( $payment_id ) : null;
		$c = $p ? (string) ( $p['meta']['tax_info']['country'] ?? '' ) : (string) ( $q['tax_country'] ?? '' );
		if ( '' !== $c && $user_id ) {
			update_user_meta( (int) $user_id, '_fw_mbr_country', strtoupper( $c ) );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Checkout fields
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _fields( $plan, $price, $quote ) {
		$s = self::settings();
		if ( 'stripe' === $s['mode'] || ! $s['rates'] ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- read-only prefill.
		$country = isset( $_REQUEST['country'] ) ? strtoupper( sanitize_key( wp_unslash( $_REQUEST['country'] ) ) ) : (string) get_user_meta( get_current_user_id(), '_fw_mbr_country', true );
		$region  = isset( $_REQUEST['region'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['region'] ) ) : '';
		$vat     = isset( $_REQUEST['vat_number'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['vat_number'] ) ) : '';
		// phpcs:enable
		$regions = false;
		foreach ( $s['rates'] as $r ) {
			$regions = $regions || '' !== trim( (string) ( $r['region'] ?? '' ) );
		}
		?>
		<p class="fw-mbr-form__row<?php echo $regions ? ' fw-mbr-form__row--half' : ''; ?>">
			<label for="fw-mbr-co-country"><?php esc_html_e( 'Country', 'fw' ); ?> <span aria-hidden="true">*</span></label>
			<select id="fw-mbr-co-country" name="country" required autocomplete="country">
				<option value=""><?php esc_html_e( 'Choose…', 'fw' ); ?></option>
				<?php foreach ( FW_Membership_Countries::all() as $code => $name ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $country, $code ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php if ( $regions ) : ?>
			<p class="fw-mbr-form__row fw-mbr-form__row--half">
				<label for="fw-mbr-co-region"><?php esc_html_e( 'State / region', 'fw' ); ?></label>
				<input type="text" id="fw-mbr-co-region" name="region" value="<?php echo esc_attr( $region ); ?>" autocomplete="address-level1" />
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $s['eu_vat'] ) ) : ?>
			<p class="fw-mbr-form__row">
				<label for="fw-mbr-co-vat"><?php esc_html_e( 'EU VAT number (businesses, optional)', 'fw' ); ?></label>
				<input type="text" id="fw-mbr-co-vat" name="vat_number" value="<?php echo esc_attr( $vat ); ?>" autocomplete="off" />
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $quote['tax_note'] ) ) : ?>
			<p class="fw-mbr-notice" role="status"><?php echo esc_html( $quote['tax_note'] ); ?></p>
		<?php elseif ( empty( $quote['tax_country'] ) ) : ?>
			<p class="fw-mbr-form__row fw-mbr-muted"><?php esc_html_e( 'Choose your country and update the price to see any tax before you pay.', 'fw' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Settings
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _save( $out, $values, $old ) {
		if ( ! isset( $values['tax'] ) ) {
			return $out;
		}
		$in    = (array) $values['tax'];
		$rates = array();
		foreach ( (array) ( $in['rates'] ?? array() ) as $r ) {
			$country = strtoupper( trim( (string) ( $r['country'] ?? '' ) ) );
			if ( '' === $country || '' === trim( (string) ( $r['rate'] ?? '' ) ) ) {
				continue;
			}
			$rates[] = array(
				'country' => '*' === $country ? '*' : substr( preg_replace( '/[^A-Z]/', '', $country ), 0, 2 ),
				'region'  => sanitize_text_field( (string) ( $r['region'] ?? '' ) ),
				'rate'    => round( max( 0, min( 100, (float) $r['rate'] ) ), 4 ),
				'label'   => sanitize_text_field( (string) ( $r['label'] ?? '' ) ),
			);
		}
		$out['tax'] = array(
			'mode'         => 'stripe' === ( $in['mode'] ?? '' ) ? 'stripe' : 'rates',
			'inclusive'    => empty( $in['inclusive'] ) ? 0 : 1,
			'shop_country' => substr( strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['shop_country'] ?? '' ) ) ), 0, 2 ),
			'rates'        => $rates,
			'eu_vat'       => empty( $in['eu_vat'] ) ? 0 : 1,
		);
		return $out;
	}

	/** @internal */
	public static function _panel( $all ) {
		$s     = self::settings();
		$rates = $s['rates'];
		$rates[] = array( 'country' => '', 'region' => '', 'rate' => '', 'label' => '' );
		$rates[] = array( 'country' => '', 'region' => '', 'rate' => '', 'label' => '' );
		?>
		<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-tax">
			<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-tax"><?php esc_html_e( 'Tax', 'fw' ); ?></h2></header>
			<div class="fw-mbr-fields">
				<p class="fw-mbr-field">
					<label for="fw-mbr-tmode"><?php esc_html_e( 'How tax is worked out', 'fw' ); ?></label>
					<select id="fw-mbr-tmode" name="tax[mode]">
						<option value="rates" <?php selected( $s['mode'], 'rates' ); ?>><?php esc_html_e( 'With my own rates (below)', 'fw' ); ?></option>
						<option value="stripe" <?php selected( $s['mode'], 'stripe' ); ?>><?php esc_html_e( 'By Stripe Tax (card payments; set it up in Stripe first)', 'fw' ); ?></option>
					</select>
				</p>
				<p class="fw-mbr-field">
					<label for="fw-mbr-tshop"><?php esc_html_e( 'Your business is in', 'fw' ); ?></label>
					<select id="fw-mbr-tshop" name="tax[shop_country]">
						<option value=""><?php esc_html_e( 'Choose…', 'fw' ); ?></option>
						<?php foreach ( FW_Membership_Countries::all() as $code => $name ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $s['shop_country'], $code ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="fw-mbr-field fw-mbr-field--wide">
					<label class="fw-mbr-check"><input type="checkbox" name="tax[inclusive]" value="1" <?php checked( ! empty( $s['inclusive'] ) ); ?> /> <?php esc_html_e( 'My prices already include tax', 'fw' ); ?></label>
					<label class="fw-mbr-check"><input type="checkbox" name="tax[eu_vat]" value="1" <?php checked( ! empty( $s['eu_vat'] ) ); ?> /> <?php esc_html_e( 'Ask EU businesses for their VAT number (a valid number from another EU country pays no VAT: reverse charge)', 'fw' ); ?></label>
				</p>
				<div class="fw-mbr-field fw-mbr-field--wide">
					<span class="fw-mbr-label"><?php esc_html_e( 'Rates', 'fw' ); ?></span>
					<table class="widefat fw-mbr-tax-rates">
						<thead><tr><th scope="col"><?php esc_html_e( 'Country code', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'State / region (optional)', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Rate %', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Name', 'fw' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $rates as $i => $r ) : ?>
							<tr>
								<td><input type="text" name="tax[rates][<?php echo (int) $i; ?>][country]" value="<?php echo esc_attr( $r['country'] ); ?>" maxlength="2" placeholder="DE" aria-label="<?php esc_attr_e( 'Country code', 'fw' ); ?>" /></td>
								<td><input type="text" name="tax[rates][<?php echo (int) $i; ?>][region]" value="<?php echo esc_attr( $r['region'] ); ?>" aria-label="<?php esc_attr_e( 'State / region', 'fw' ); ?>" /></td>
								<td><input type="number" step="0.001" min="0" max="100" name="tax[rates][<?php echo (int) $i; ?>][rate]" value="<?php echo esc_attr( (string) $r['rate'] ); ?>" aria-label="<?php esc_attr_e( 'Rate', 'fw' ); ?>" /></td>
								<td><input type="text" name="tax[rates][<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $r['label'] ); ?>" placeholder="<?php esc_attr_e( 'VAT', 'fw' ); ?>" aria-label="<?php esc_attr_e( 'Name', 'fw' ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<span class="description"><?php esc_html_e( 'Two-letter country codes (DE, FR, US…); * means every other country. A row with a region applies only there. Leave a row empty to remove it; save to get more empty rows.', 'fw' ); ?></span>
				</div>
			</div>
		</section>
		<?php
	}
}
