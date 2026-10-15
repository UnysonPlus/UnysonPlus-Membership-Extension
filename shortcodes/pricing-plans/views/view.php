<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}
/**
 * Pricing Plans. In scope: $atts.
 *
 * When the plans shown have both monthly and yearly prices, a switch shows one or the
 * other; every price is in the markup (data-interval), so it works without waiting for
 * anything and the switch only toggles `hidden`.
 */
if ( ! class_exists( 'FW_Membership_Plans' ) ) {
	return;
}
FW_Membership_Denied::enqueue();

$ids   = class_exists( 'FW_Membership_Elements' ) ? FW_Membership_Elements::plan_ids( $atts['plans'] ?? array() ) : array();
$plans = array();
foreach ( FW_Membership_Plans::all( 'active' ) as $p ) {
	if ( ( ! $ids || in_array( $p['id'], $ids, true ) ) && ! FW_Membership_Plans::is_item( $p ) ) {
		$plans[] = $p;
	}
}
usort( $plans, function ( $a, $b ) {
	return $a['priority'] <=> $b['priority'];
} );

$payments_on = class_exists( 'FW_Membership_Gateways' );
if ( ! $plans || ( ! $payments_on && ! current_user_can( 'manage_options' ) ) ) {
	return;
}

$direct   = array();
$switch   = array(); // plans the visitor reaches by changing a plan they hold, not by buying another
$replace  = array(); // plan id => the membership a purchase of it replaces (a paid move without a subscription)
foreach ( is_user_logged_in() ? FW_Membership_Memberships::query( array( 'user_id' => get_current_user_id(), 'status' => 'access', 'per_page' => 50 ) ) : array() as $m ) {
	$direct[] = $m['plan_id'];
	if ( class_exists( 'FW_Membership_Plan_Change' ) ) {
		foreach ( FW_Membership_Plan_Change::options( $m ) as $o ) {
			if ( 'checkout' !== $o['when'] ) {
				$switch[] = $o['plan']['id'];
			} else {
				$replace[ $o['plan']['id'] ] = $m['id'];
			}
		}
	}
}
$has      = array( 'month' => false, 'year' => false );
foreach ( $plans as $p ) {
	foreach ( $p['prices'] as $pr ) {
		if ( isset( $has[ $pr['billing'] ] ) ) {
			$has[ $pr['billing'] ] = true;
		}
	}
}
$toggle   = $has['month'] && $has['year'];
$featured = (int) ( $atts['featured'] ?? 0 );
// A ?coupon=CODE link to the pricing page carries the code on to the checkout.
$coupon   = isset( $_GET['coupon'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', sanitize_text_field( wp_unslash( $_GET['coupon'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

$atts['base_class']       = 'fw-mbr-pricing';
$atts['unique_id_prefix'] = 'pp-';
$attr                     = function_exists( 'sc_build_wrapper_attr' ) ? sc_build_wrapper_attr( $atts ) : array( 'class' => 'fw-mbr-pricing' );

/**
 * The price a plan shows for an interval: that interval's price, else its first.
 */
$price_for = function ( array $plan, $interval ) {
	foreach ( $plan['prices'] as $pr ) {
		if ( $pr['billing'] === $interval ) {
			return $pr;
		}
	}
	return $plan['prices'][0] ?? null;
};
$amount_html = function ( $pr ) {
	if ( ! $pr || 'free' === $pr['billing'] || ( ! $pr['amount'] && in_array( $pr['billing'], array( 'once', 'lifetime' ), true ) ) ) {
		return esc_html__( 'Free', 'fw' );
	}
	$per = array( 'day' => __( '/ day', 'fw' ), 'week' => __( '/ week', 'fw' ), 'month' => __( '/ month', 'fw' ), 'year' => __( '/ year', 'fw' ), 'lifetime' => __( 'once, for life', 'fw' ), 'once' => __( 'once', 'fw' ) );
	$n   = (int) $pr['interval_count'];
	$suf = isset( $per[ $pr['billing'] ] ) ? ( $n > 1 && in_array( $pr['billing'], array( 'day', 'week', 'month', 'year' ), true ) ? sprintf( __( 'every %1$d %2$ss', 'fw' ), $n, $pr['billing'] ) : $per[ $pr['billing'] ] ) : '';
	return '<span class="fw-mbr-plan__amount">' . esc_html( FW_Membership_Plans::money( $pr['amount'], $pr['currency'] ) ) . '</span> <small>' . esc_html( $suf ) . '</small>';
};
?>
<div <?php echo fw_attr_to_html( $attr ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php if ( ! $payments_on ) : ?>
		<p class="fw-mbr-notice"><?php esc_html_e( 'Administrators only: switch on the Payments module (Membership → Modules) so the buttons can take people to a checkout.', 'fw' ); ?></p>
	<?php endif; ?>
	<?php if ( $toggle ) : ?>
		<div class="fw-mbr-pricing__toggle-wrap">
			<div class="fw-mbr-pricing__toggle" role="group" aria-label="<?php esc_attr_e( 'Billing period', 'fw' ); ?>">
				<button type="button" data-interval="month" aria-pressed="true"><?php echo esc_html( (string) ( $atts['monthly_text'] ?? __( 'Monthly', 'fw' ) ) ); ?></button>
				<button type="button" data-interval="year" aria-pressed="false"><?php echo esc_html( (string) ( $atts['yearly_text'] ?? __( 'Yearly', 'fw' ) ) ); ?></button>
			</div>
		</div>
	<?php endif; ?>
	<div class="fw-mbr-pricing__grid">
		<?php foreach ( $plans as $plan ) : ?>
			<?php
			$intervals = $toggle ? array( 'month', 'year' ) : array( '' );
			$mine      = in_array( $plan['id'], $direct, true );
			?>
			<article class="fw-mbr-plan<?php echo $featured === $plan['id'] ? ' is-featured' : ''; ?>">
				<?php if ( $featured === $plan['id'] && ! empty( $atts['featured_text'] ) ) : ?>
					<span class="fw-mbr-plan__badge"><?php echo esc_html( $atts['featured_text'] ); ?></span>
				<?php endif; ?>
				<h3 class="fw-mbr-plan__name"><?php echo esc_html( $plan['name'] ); ?></h3>
				<?php foreach ( $intervals as $i => $interval ) : ?>
					<?php $pr = '' === $interval ? ( $plan['prices'][0] ?? null ) : $price_for( $plan, $interval ); ?>
					<div class="fw-mbr-plan__offer"<?php echo '' !== $interval ? ' data-interval="' . esc_attr( $interval ) . '"' . ( $i > 0 ? ' hidden' : '' ) : ''; ?>>
						<p class="fw-mbr-plan__price"><?php echo $amount_html( $pr ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<?php if ( $pr && (int) $pr['trial_days'] > 0 ) : ?>
							<p class="fw-mbr-plan__length"><?php echo esc_html( sprintf( _n( '%d-day free trial', '%d-day free trial', (int) $pr['trial_days'], 'fw' ), (int) $pr['trial_days'] ) ); ?></p>
						<?php endif; ?>
						<div class="fw-mbr-plan__cta-src" hidden data-href="<?php echo esc_attr( $payments_on ? FW_Membership_Gateways::checkout_url( array( 'plan' => $plan['slug'], 'price' => $pr ? $pr['id'] : 0, 'replaces' => $replace[ $plan['id'] ] ?? 0, 'coupon' => $coupon ) ) : '' ); ?>"></div>
					</div>
				<?php endforeach; ?>
				<?php if ( '' !== trim( (string) $plan['description'] ) ) : ?>
					<p class="fw-mbr-plan__desc"><?php echo esc_html( $plan['description'] ); ?></p>
				<?php endif; ?>
				<p class="fw-mbr-plan__length"><?php echo esc_html( FW_Membership_Plans::duration_label( $plan ) ); ?></p>
				<div class="fw-mbr-plan__cta">
					<?php if ( ! $mine && in_array( $plan['id'], $switch, true ) ) : ?>
						<a class="<?php echo esc_attr( FW_Membership_Settings::button_class( $featured !== $plan['id'] && $featured ) ); ?>" href="<?php echo esc_url( FW_Membership_Settings::account_url() . '#fw-mbr-account' ); ?>"><?php echo esc_html( (string) ( $atts['change_text'] ?? __( 'Change plan', 'fw' ) ) ); ?></a>
					<?php elseif ( $mine ) : ?>
						<span class="<?php echo esc_attr( FW_Membership_Settings::button_class( true ) ); ?>" aria-disabled="true"><?php echo esc_html( (string) ( $atts['current_text'] ?? __( 'Your plan', 'fw' ) ) ); ?></span>
					<?php else : ?>
						<?php
						$first = '' === $intervals[0] ? ( $plan['prices'][0] ?? null ) : $price_for( $plan, $intervals[0] );
						$href  = $payments_on ? FW_Membership_Gateways::checkout_url( array( 'plan' => $plan['slug'], 'price' => $first ? $first['id'] : 0, 'replaces' => $replace[ $plan['id'] ] ?? 0, 'coupon' => $coupon ) ) : '';
						?>
						<a class="<?php echo esc_attr( FW_Membership_Settings::button_class( $featured !== $plan['id'] && $featured ) ); ?> fw-mbr-plan__join" href="<?php echo esc_url( '' !== $href ? $href : '#' ); ?>"<?php echo '' === $href ? ' aria-disabled="true"' : ''; ?>><?php echo esc_html( (string) ( $atts['button_text'] ?? __( 'Join', 'fw' ) ) ); ?></a>
					<?php endif; ?>
				</div>
				<?php
				$gift_price = '' === $intervals[0] ? ( $plan['prices'][0] ?? null ) : $price_for( $plan, $intervals[0] );
				if ( class_exists( 'FW_Membership_Gifts' ) && $payments_on && $gift_price && (float) $gift_price['amount'] > 0 && '' !== trim( (string) ( $atts['gift_text'] ?? __( 'Give as a gift', 'fw' ) ) ) ) :
					?>
					<a class="fw-mbr-plan__gift" href="<?php echo esc_url( FW_Membership_Gateways::checkout_url( array( 'plan' => $plan['slug'], 'price' => $gift_price['id'], 'gift' => 1 ) ) ); ?>"><?php echo esc_html( (string) ( $atts['gift_text'] ?? __( 'Give as a gift', 'fw' ) ) ); ?></a>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
	<?php if ( $toggle ) : ?>
		<script>
		( function ( root ) {
			if ( ! root ) { return; }
			root.querySelectorAll( '.fw-mbr-pricing__toggle button' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var interval = btn.getAttribute( 'data-interval' );
					root.querySelectorAll( '.fw-mbr-pricing__toggle button' ).forEach( function ( b ) { b.setAttribute( 'aria-pressed', b === btn ? 'true' : 'false' ); } );
					root.querySelectorAll( '.fw-mbr-plan' ).forEach( function ( plan ) {
						var shown = null;
						plan.querySelectorAll( '.fw-mbr-plan__offer[data-interval]' ).forEach( function ( o ) {
							var on = o.getAttribute( 'data-interval' ) === interval;
							o.hidden = ! on;
							if ( on ) { shown = o; }
						} );
						var join = plan.querySelector( '.fw-mbr-plan__join' );
						var src = shown && shown.querySelector( '.fw-mbr-plan__cta-src' );
						if ( join && src && src.getAttribute( 'data-href' ) ) { join.setAttribute( 'href', src.getAttribute( 'data-href' ) ); }
					} );
				} );
			} );
		}( document.currentScript && document.currentScript.closest( '.fw-mbr-pricing' ) ) );
		</script>
	<?php endif; ?>
</div>
