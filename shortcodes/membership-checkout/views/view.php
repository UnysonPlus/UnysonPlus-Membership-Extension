<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}
/**
 * Membership Checkout. In scope: $atts. The form posts back to this page and is
 * handled by FW_Membership_Checkout before any output; the outcome of a payment (or an
 * error) is in FW_Membership_Checkout::$outcome.
 */
if ( ! class_exists( 'FW_Membership_Plans' ) ) {
	return;
}
FW_Membership_Denied::enqueue();

$atts['base_class']       = 'fw-mbr-checkout';
$atts['unique_id_prefix'] = 'co-';
$attr                     = function_exists( 'sc_build_wrapper_attr' ) ? sc_build_wrapper_attr( $atts ) : array( 'class' => 'fw-mbr-checkout' );
$attr['id']               = 'fw-mbr-checkout';
$t                        = function ( $key, $default ) use ( $atts ) {
	return isset( $atts[ $key ] ) && '' !== trim( (string) $atts[ $key ] ) ? (string) $atts[ $key ] : $default;
};

echo '<div ' . fw_attr_to_html( $attr ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput

if ( ! class_exists( 'FW_Membership_Checkout' ) ) {
	if ( current_user_can( 'manage_options' ) ) {
		echo '<p class="fw-mbr-notice">' . esc_html__( 'Administrators only: switch on the Payments module (Membership → Modules) to use the checkout.', 'fw' ) . '</p>';
	}
	echo '</div>';
	return;
}

$outcome = FW_Membership_Checkout::$outcome;

/** Replace the checkout with another step (the Gifts module's redeem page). */
$takeover = (string) apply_filters( 'unysonplus_membership_checkout_takeover', '', $atts, $outcome );
if ( '' !== $takeover ) {
	echo $takeover . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	return;
}

// After the payment page.
if ( $outcome && in_array( $outcome['type'], array( 'paid', 'instructions', 'processing' ), true ) ) {
	$p    = $outcome['payment'];
	$plan = FW_Membership_Plans::get( $p['plan_id'] );
	$gift = ! empty( $p['meta']['gift'] ) && class_exists( 'FW_Membership_Gifts' ) ? FW_Membership_Gifts::by_payment( $p['id'] ) : null;
	if ( $gift ) {
		echo '<p class="fw-mbr-notice fw-mbr-notice--success" role="status">' . esc_html( 'paid' === $outcome['type'] ? sprintf( __( 'Thank you! Your gift is on its way to %s.', 'fw' ), $gift['recipient_email'] ) : sprintf( __( 'Thank you! Your gift is sent to %s as soon as the payment arrives.', 'fw' ), $gift['recipient_email'] ) ) . '</p>';
		if ( 'instructions' === $outcome['type'] ) {
			$g = FW_Membership_Gateways::get( 'offline' );
			echo '<p><strong>' . esc_html( sprintf( __( 'Amount: %s', 'fw' ), FW_Membership_Plans::money( $p['amount'], $p['currency'] ) ) ) . '</strong></p>';
			echo '<div class="fw-mbr-checkout__instructions">' . esc_html( strtr( (string) ( $g ? $g->setting( 'instructions' ) : '' ), array( '{reference}' => $p['gateway_ref'] ) ) ) . '</div>';
		}
	} elseif ( 'paid' === $outcome['type'] ) {
		echo '<p class="fw-mbr-notice fw-mbr-notice--success" role="status">' . esc_html( $t( 'thanks_text', __( 'Thank you! Your membership is active.', 'fw' ) ) ) . '</p>';
		$item_url = '';
		if ( $plan && FW_Membership_Plans::is_item( $plan ) ) {
			$item_url = 'file' === $plan['settings']['item_type'] && class_exists( 'FW_Membership_Files' ) ? FW_Membership_Files::url( (int) $plan['settings']['item_id'] ) : (string) get_permalink( (int) $plan['settings']['item_id'] );
		}
		if ( '' !== $item_url ) {
			echo '<p><a class="' . esc_attr( FW_Membership_Settings::button_class() ) . '" href="' . esc_url( $item_url ) . '">' . esc_html( 'file' === $plan['settings']['item_type'] ? __( 'Download it now', 'fw' ) : __( 'Open it now', 'fw' ) ) . '</a></p>';
		} else {
			echo '<p><a class="' . esc_attr( FW_Membership_Settings::button_class() ) . '" href="' . esc_url( FW_Membership_Settings::account_url() ) . '">' . esc_html__( 'Go to your account', 'fw' ) . '</a></p>';
		}
	} elseif ( 'instructions' === $outcome['type'] ) {
		$g = FW_Membership_Gateways::get( 'offline' );
		echo '<p class="fw-mbr-notice" role="status">' . esc_html( sprintf( __( 'Almost done: your %s membership starts as soon as your payment arrives.', 'fw' ), $plan ? $plan['name'] : '' ) ) . '</p>';
		echo '<p><strong>' . esc_html( sprintf( __( 'Amount: %s', 'fw' ), FW_Membership_Plans::money( $p['amount'], $p['currency'] ) ) ) . '</strong></p>';
		echo '<div class="fw-mbr-checkout__instructions">' . esc_html( strtr( (string) ( $g ? $g->setting( 'instructions' ) : '' ), array( '{reference}' => $p['gateway_ref'] ) ) ) . '</div>';
	} else {
		echo '<p class="fw-mbr-notice" role="status">' . esc_html__( 'Thank you! We are confirming your payment with the payment service. This usually takes a moment; refresh this page or check your account shortly.', 'fw' ) . '</p>';
	}
	echo '</div>';
	return;
}

list( $plan, $price ) = FW_Membership_Checkout::requested();
if ( ! $plan || ! $price ) {
	echo '<p>' . esc_html( $t( 'no_plan_text', __( 'Choose a plan to get started.', 'fw' ) ) );
	if ( ! empty( $atts['plans_link'] ) ) {
		echo ' <a href="' . esc_url( $atts['plans_link'] ) . '">' . esc_html__( 'See the plans', 'fw' ) . '</a>';
	}
	echo '</p></div>';
	return;
}

$is_gift = ! empty( $_REQUEST['gift'] ) && class_exists( 'FW_Membership_Gifts' ); // phpcs:ignore WordPress.Security.NonceVerification
if ( ! $is_gift && is_user_logged_in() && FW_Membership_Memberships::query( array( 'user_id' => get_current_user_id(), 'plan_id' => $plan['id'], 'status' => 'access', 'per_page' => 1 ) ) ) {
	echo '<p class="fw-mbr-notice">' . esc_html( sprintf( __( 'You already have %s.', 'fw' ), $plan['name'] ) ) . ' <a href="' . esc_url( FW_Membership_Settings::account_url() ) . '">' . esc_html__( 'Your account', 'fw' ) . '</a></p></div>';
	return;
}

$free     = 'free' === $price['billing'] || ( (float) $price['amount'] <= 0 && (float) $price['setup_fee'] <= 0 && ! FW_Membership_Payments::is_recurring( $price ) );
$gateways = $free ? array() : FW_Membership_Gateways::available_for( $is_gift ? array_merge( $price, array( 'billing' => 'once' ) ) : $price ); // a gift is paid once
$terms    = (string) ( FW_Membership_Gateways::settings()['terms_url'] ?? '' );
$here     = FW_Membership_Checkout::here();
$replacing = FW_Membership_Checkout::replacing( $plan );
?>
	<?php if ( $outcome && 'error' === $outcome['type'] ) : ?>
		<p class="fw-mbr-notice fw-mbr-notice--error" role="alert"><?php echo esc_html( $outcome['message'] ); ?></p>
	<?php elseif ( $outcome && 'cancelled' === $outcome['type'] ) : ?>
		<p class="fw-mbr-notice" role="status"><?php esc_html_e( 'The payment was cancelled. You can try again below.', 'fw' ); ?></p>
	<?php endif; ?>

	<?php
	$buyer = FW_Membership_Checkout::buyer_args();
	$quote = FW_Membership_Payments_Flow::quote( $plan, $price, array_merge( $buyer, array( 'user_id' => get_current_user_id() ) ) );
	if ( $quote['errors'] && ! empty( $buyer['coupon'] ) ) {
		$coupon_error = implode( ' ', $quote['errors'] );
		unset( $buyer['coupon'] );
		$quote = FW_Membership_Payments_Flow::quote( $plan, $price, array_merge( $buyer, array( 'user_id' => get_current_user_id() ) ) );
	}
	if ( ! $free && ! $quote['recurring'] && $quote['total'] <= 0 ) {
		$free     = true; // a code took the whole price: nothing to pay
		$gateways = array();
	}
	$cur   = $quote['currency'];
	$money = function ( $n ) use ( $cur ) {
		return FW_Membership_Plans::money( $n, $cur );
	};
	?>
	<div class="fw-mbr-checkout__summary">
		<div>
			<strong><?php echo esc_html( $plan['name'] ); ?></strong>
			<span><?php echo esc_html( $is_gift ? __( 'A gift: one payment, nothing renews', 'fw' ) : FW_Membership_Plans::duration_label( $plan ) ); ?></span>
		</div>
		<div style="text-align:right">
			<?php
			$label = FW_Membership_Plans::price_label( $price );
			if ( $is_gift && in_array( $price['billing'], array( 'day', 'week', 'month', 'year' ), true ) ) {
				// A gift is paid once and covers one period of a repeating price.
				$n     = max( 1, (int) $price['interval_count'] );
				$units = array(
					'day'   => _n( '%d day', '%d days', $n, 'fw' ),
					'week'  => _n( '%d week', '%d weeks', $n, 'fw' ),
					'month' => _n( '%d month', '%d months', $n, 'fw' ),
					'year'  => _n( '%d year', '%d years', $n, 'fw' ),
				);
				$label = sprintf( __( '%1$s for %2$s', 'fw' ), trim( $price['currency'] . ' ' . number_format_i18n( $price['amount'], 2 ) ), sprintf( $units[ $price['billing'] ], $n ) );
			}
			?>
			<strong><?php echo esc_html( $label ); ?></strong>
			<?php if ( ! $is_gift && (int) $price['trial_days'] > 0 ) : ?>
				<span><?php echo esc_html( sprintf( __( 'Free for the first %d days', 'fw' ), (int) $price['trial_days'] ) ); ?></span>
			<?php endif; ?>
		</div>
	</div>
	<?php if ( $quote['discount'] > 0 || $quote['tax'] > 0 || $quote['setup_fee'] > 0 || ( $quote['recurring'] && $quote['trial_days'] > 0 ) ) : ?>
		<table class="fw-mbr-checkout__lines">
			<tbody>
				<?php if ( $quote['setup_fee'] > 0 ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Setup fee', 'fw' ); ?></th><td><?php echo esc_html( $money( $quote['setup_fee'] ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( $quote['discount'] > 0 ) : ?>
					<tr><th scope="row"><?php echo esc_html( sprintf( __( 'Discount (%s)', 'fw' ), $quote['coupon']['code'] ?? '' ) ); ?></th><td>−<?php echo esc_html( $money( $quote['discount'] ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( $quote['tax'] > 0 ) : ?>
					<tr><th scope="row"><?php echo esc_html( $quote['tax_inclusive'] ? sprintf( __( 'Includes %s', 'fw' ), $quote['tax_label'] ) : $quote['tax_label'] ); ?></th><td><?php echo esc_html( $money( $quote['tax'] ) ); ?></td></tr>
				<?php endif; ?>
				<tr class="fw-mbr-checkout__total"><th scope="row"><?php esc_html_e( 'Due today', 'fw' ); ?></th><td><?php echo esc_html( $money( $quote['total'] ) ); ?></td></tr>
				<?php if ( $quote['recurring'] && $quote['renewal_total'] > 0 ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Then each renewal', 'fw' ); ?></th><td><?php echo esc_html( $money( $quote['renewal_total'] ) ); ?><?php echo $quote['coupon'] && 'repeating' === $quote['coupon']['duration'] ? ' ' . esc_html( sprintf( _n( '(discounted for %d month)', '(discounted for %d months)', $quote['coupon']['months'], 'fw' ), $quote['coupon']['months'] ) ) : ''; ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php if ( $quote['coupon'] ) : ?>
		<p class="fw-mbr-notice fw-mbr-notice--success" role="status"><?php echo esc_html( sprintf( __( 'Code %1$s: %2$s.', 'fw' ), $quote['coupon']['code'], FW_Membership_Coupons::label( $quote['coupon'] ) ) ); ?></p>
	<?php elseif ( ! empty( $coupon_error ) ) : ?>
		<p class="fw-mbr-notice fw-mbr-notice--error" role="alert"><?php echo esc_html( $coupon_error ); ?></p>
	<?php endif; ?>
	<?php
	ob_start();
	/** Fields that change the price (the Tax module adds country / region / VAT number). */
	do_action( 'unysonplus_membership_checkout_price_fields', $plan, $price, $quote );
	$price_fields = (string) ob_get_clean();
	$has_coupons  = ( class_exists( 'FW_Membership_Coupons' ) && ( ! $free || $quote['coupon'] ) ) || class_exists( 'FW_Membership_Gifts' ); // a gift code works here too
	?>
	<?php if ( '' !== trim( $price_fields ) || $has_coupons ) : ?>
		<form method="get" class="fw-mbr-form fw-mbr-checkout__price" action="<?php echo esc_url( $here ); ?>">
			<input type="hidden" name="plan" value="<?php echo esc_attr( $plan['slug'] ); ?>" />
			<input type="hidden" name="price" value="<?php echo (int) $price['id']; ?>" />
			<?php if ( $replacing ) : ?><input type="hidden" name="replaces" value="<?php echo (int) $replacing['id']; ?>" /><?php endif; ?>
			<?php echo $price_fields; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the module. ?>
			<div class="fw-mbr-checkout__coupon">
				<?php if ( $has_coupons ) : ?>
					<label for="fw-mbr-co-coupon"><?php esc_html_e( 'Have a code?', 'fw' ); ?></label>
					<input type="text" id="fw-mbr-co-coupon" name="coupon" value="<?php echo esc_attr( $quote['coupon']['code'] ?? '' ); ?>" autocomplete="off" />
				<?php endif; ?>
				<button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class( true ) ); ?>"><?php echo esc_html( '' !== trim( $price_fields ) ? __( 'Update the price', 'fw' ) : __( 'Apply', 'fw' ) ); ?></button>
			</div>
		</form>
	<?php endif; ?>

	<?php if ( ! $free && ! $gateways ) : ?>
		<p class="fw-mbr-notice"><?php esc_html_e( 'Online payment is not available for this plan yet. Please contact us to join.', 'fw' ); ?></p>
	<?php else : ?>
		<form method="post" class="fw-mbr-form" action="<?php echo esc_url( $here ); ?>">
			<?php wp_nonce_field( FW_Membership_Checkout::NONCE ); ?>
			<input type="hidden" name="fw_mbr_checkout" value="1" />
			<input type="hidden" name="plan" value="<?php echo esc_attr( $plan['slug'] ); ?>" />
			<input type="hidden" name="price" value="<?php echo (int) $price['id']; ?>" />
			<?php if ( $replacing ) : ?>
				<input type="hidden" name="replaces" value="<?php echo (int) $replacing['id']; ?>" />
			<?php endif; ?>
			<?php foreach ( $buyer as $bk => $bv ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $bk ); ?>" value="<?php echo esc_attr( $bv ); ?>" />
			<?php endforeach; ?>
			<?php
			/** More fields in the payment form. */
			do_action( 'unysonplus_membership_checkout_fields', $plan, $price, $quote );
			?>

			<?php if ( ! is_user_logged_in() ) : ?>
				<p class="fw-mbr-form__row"><?php esc_html_e( 'Already have an account?', 'fw' ); ?> <a href="<?php echo esc_url( FW_Membership_Settings::login_url( $here ) ); ?>"><?php esc_html_e( 'Log in first', 'fw' ); ?></a></p>
				<p class="fw-mbr-form__row fw-mbr-form__row--half">
					<label for="fw-mbr-co-first"><?php esc_html_e( 'First name', 'fw' ); ?></label>
					<input type="text" id="fw-mbr-co-first" name="first_name" autocomplete="given-name" />
				</p>
				<p class="fw-mbr-form__row fw-mbr-form__row--half">
					<label for="fw-mbr-co-last"><?php esc_html_e( 'Last name', 'fw' ); ?></label>
					<input type="text" id="fw-mbr-co-last" name="last_name" autocomplete="family-name" />
				</p>
				<p class="fw-mbr-form__row">
					<label for="fw-mbr-co-email"><?php esc_html_e( 'Email', 'fw' ); ?> <span aria-hidden="true">*</span></label>
					<input type="email" id="fw-mbr-co-email" name="email" autocomplete="email" required />
				</p>
				<p class="fw-mbr-form__row">
					<label for="fw-mbr-co-pass"><?php esc_html_e( 'Choose a password', 'fw' ); ?> <span aria-hidden="true">*</span></label>
					<input type="password" id="fw-mbr-co-pass" name="password" autocomplete="new-password" minlength="8" required />
				</p>
			<?php else : ?>
				<?php if ( $replacing ) : ?>
					<?php $from_plan = FW_Membership_Plans::get( $replacing['plan_id'] ); ?>
					<p class="fw-mbr-form__row"><?php echo esc_html( sprintf( __( 'Switching from %s. It ends as soon as this payment is made.', 'fw' ), $from_plan ? $from_plan['name'] : '' ) ); ?></p>
				<?php else : ?>
					<p class="fw-mbr-form__row"><?php echo esc_html( sprintf( __( 'Joining as %s.', 'fw' ), wp_get_current_user()->user_email ) ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $gateways ) : ?>
				<fieldset class="fw-mbr-checkout__gateways fw-mbr-form__row">
					<legend><?php esc_html_e( 'How would you like to pay?', 'fw' ); ?></legend>
					<?php $first = true; foreach ( $gateways as $gid => $g ) : ?>
						<label class="fw-mbr-checkout__gateway">
							<input type="radio" name="gateway" value="<?php echo esc_attr( $gid ); ?>" <?php checked( $first ); ?> required />
							<span><strong><?php echo esc_html( $g->checkout_label() ); ?></strong>
								<?php if ( '' !== $g->checkout_description() ) : ?><small><?php echo esc_html( $g->checkout_description() ); ?></small><?php endif; ?>
							</span>
						</label>
					<?php $first = false; endforeach; ?>
				</fieldset>
			<?php endif; ?>

			<?php if ( '' !== $terms ) : ?>
				<p class="fw-mbr-form__row fw-mbr-form__row--check">
					<label><input type="checkbox" name="terms" value="1" required /> <?php echo wp_kses( sprintf( __( 'I accept the <a href="%s" target="_blank" rel="noopener">terms</a>.', 'fw' ), esc_url( $terms ) ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></label>
				</p>
			<?php endif; ?>

			<p class="fw-mbr-form__actions">
				<button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class() ); ?>"><?php echo esc_html( $free ? $t( 'free_text', __( 'Join for free', 'fw' ) ) : $t( 'button_text', __( 'Continue to payment', 'fw' ) ) ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>
