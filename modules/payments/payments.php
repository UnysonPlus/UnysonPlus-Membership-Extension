<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Payments module (Membership → Modules → Payments). Loaded only while switched on.
 *
 *   class-fw-membership-gateways.php       the gateway interface + registry + settings
 *   class-fw-membership-payments.php       the payments repository
 *   class-fw-membership-payments-flow.php  paid / renewed / failed / cancelled / refunded
 *   class-fw-membership-checkout.php       the checkout POST and the return from payment
 *   class-fw-membership-plan-change.php    members upgrade / downgrade (account page)
 *   class-fw-membership-invoices.php       numbered, printable invoices
 *   class-fw-membership-single-purchase.php  pay per item (one post or file, no plan)
 *   gateways/                              Stripe, PayPal, bank transfer
 *   admin/                                 Membership → Payments (list + settings)
 *
 * The Pricing Plans and Membership Checkout elements live in the extension's shortcodes/
 * folder (the page builder loads them regardless) and say so when this module is off.
 */

$fw_mbr_pay_dir = dirname( __FILE__ );
require_once $fw_mbr_pay_dir . '/class-fw-membership-gateways.php';
require_once $fw_mbr_pay_dir . '/class-fw-membership-payments.php';
require_once $fw_mbr_pay_dir . '/class-fw-membership-payments-flow.php';
require_once $fw_mbr_pay_dir . '/class-fw-membership-checkout.php';
require_once $fw_mbr_pay_dir . '/class-fw-membership-plan-change.php';
require_once $fw_mbr_pay_dir . '/class-fw-membership-invoices.php';
require_once $fw_mbr_pay_dir . '/class-fw-membership-single-purchase.php';
require_once $fw_mbr_pay_dir . '/gateways/class-fw-membership-gateway-stripe.php';
require_once $fw_mbr_pay_dir . '/gateways/class-fw-membership-gateway-offline.php';
require_once $fw_mbr_pay_dir . '/gateways/class-fw-membership-gateway-paypal.php';
if ( is_admin() ) {
	require_once $fw_mbr_pay_dir . '/admin/class-fw-membership-admin-payments.php';
	FW_Membership_Admin_Payments::init();
}

FW_Membership_Payments_Flow::init();
FW_Membership_Checkout::init();
FW_Membership_Plan_Change::init();
FW_Membership_Invoices::init();
FW_Membership_Single_Purchase::init();

/** Webhooks: POST /wp-json/fw-membership/v1/webhooks/{gateway}. */
add_action( 'rest_api_init', function () {
	register_rest_route( FW_Membership_REST::NS, '/webhooks/(?P<gateway>[a-z0-9_]+)', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // verified by the gateway (signature)
		'callback'            => function ( WP_REST_Request $r ) {
			$g = FW_Membership_Gateways::get( (string) $r['gateway'] );
			return $g ? $g->webhook( $r ) : new WP_Error( 'fw_mbr_no_gateway', 'Unknown gateway.', array( 'status' => 404 ) );
		},
	) );
} );

/** A deleted account's waiting payments are closed; paid ones stay on record. */
add_action( 'deleted_user', function ( $user_id ) {
	foreach ( FW_Membership_Payments::query( array( 'user_id' => (int) $user_id, 'status' => 'pending', 'per_page' => 500 ) ) as $p ) {
		FW_Membership_Payments::update( $p['id'], array( 'status' => 'cancelled' ) );
	}
} );

/** The Payments area is real now (it is "Soon" while the module is off). */
add_filter( 'unysonplus_membership_nav', function ( $nav ) {
	if ( isset( $nav['selling']['items']['payments'] ) ) {
		unset( $nav['selling']['items']['payments']['soon'] );
	}
	return $nav;
} );

/** Receipt and payment-failed emails. */
add_filter( 'unysonplus_membership_email_catalogue', function ( $catalogue ) {
	$catalogue['receipt']        = array(
		'label'   => __( 'Payment receipt', 'fw' ),
		'to'      => 'member',
		'when'    => __( 'After each successful payment, including renewals.', 'fw' ),
		'subject' => __( 'Your receipt from {site_name}', 'fw' ),
		'body'    => __( "Hi {first_name},\n\nThank you for your payment.\n\n{plan}: {amount}\nDate: {payment_date}\nReference: {payment_ref}\nInvoice: {invoice_url}\n\nYour account: {account_url}", 'fw' ),
	);
	$catalogue['payment_failed'] = array(
		'label'   => __( 'Payment failed', 'fw' ),
		'to'      => 'member',
		'when'    => __( 'When a renewal payment fails. Your access continues for a few days while the payment is retried.', 'fw' ),
		'subject' => __( 'Your {plan} payment did not go through', 'fw' ),
		'body'    => __( "Hi {first_name},\n\nWe could not take the payment for your {plan} membership. Please update your payment method so your membership continues:\n\n{account_url}", 'fw' ),
	);
	return $catalogue;
} );

add_action( 'unysonplus_membership_payment_paid', function ( $p ) {
	if ( ! $p || $p['amount'] <= 0 ) {
		return;
	}
	$p    = FW_Membership_Payments::get( $p['id'] ); // with its invoice number (given at priority 1)
	$plan = FW_Membership_Plans::get( $p['plan_id'] );
	FW_Membership_Emails::send( 'receipt', $p['user_id'], array(
		'plan'         => $plan ? $plan['name'] : '',
		'amount'       => FW_Membership_Payments::money( $p['amount'], $p['currency'] ),
		'payment_date' => date_i18n( get_option( 'date_format' ), current_time( 'timestamp' ) ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		'payment_ref'  => '' !== (string) $p['invoice_number'] ? $p['invoice_number'] : ( '' !== (string) $p['gateway_ref'] ? $p['gateway_ref'] : '#' . $p['id'] ),
		'invoice_url'  => FW_Membership_Invoices::url( $p ),
	) );
} );

add_action( 'unysonplus_membership_payment_failed', function ( $m ) {
	$plan = FW_Membership_Plans::get( $m['plan_id'] );
	FW_Membership_Emails::send( 'payment_failed', $m['user_id'], array( 'plan' => $plan ? $plan['name'] : '' ) );
}, 20 );

/** The member's account page: payments, and a way to update the card. */
add_action( 'unysonplus_membership_account_sections', function ( $user ) {
	$payments = FW_Membership_Payments::query( array( 'user_id' => $user->ID, 'per_page' => 20 ) );
	$payments = array_values( array_filter( $payments, function ( $p ) {
		return in_array( $p['status'], array( 'paid', 'refunded' ), true ) || ( 'pending' === $p['status'] && 'offline' === $p['gateway'] );
	} ) );
	$managed  = array();
	foreach ( FW_Membership_Memberships::query( array( 'user_id' => $user->ID, 'status' => 'access', 'per_page' => 20 ) ) as $m ) {
		if ( '' !== (string) $m['gateway_subscription_id'] ) {
			$managed[ $m['gateway'] ] = true;
		}
	}
	if ( ! $payments && ! $managed ) {
		return;
	}
	$labels = array( 'paid' => __( 'Paid', 'fw' ), 'refunded' => __( 'Refunded', 'fw' ), 'pending' => __( 'Waiting for payment', 'fw' ) );
	?>
	<section class="fw-mbr-account__section" aria-labelledby="fw-mbr-acc-pay">
		<h2 id="fw-mbr-acc-pay"><?php esc_html_e( 'Payments', 'fw' ); ?></h2>
		<?php if ( $payments ) : ?>
			<ul class="fw-mbr-account__payments">
				<?php foreach ( $payments as $p ) : ?>
					<?php $plan = FW_Membership_Plans::get( $p['plan_id'] ); ?>
					<li>
						<span><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $p['created_at'] ) ) ); ?></span>
						<span><?php echo $plan ? esc_html( $plan['name'] ) : ''; ?></span>
						<strong><?php echo esc_html( FW_Membership_Payments::money( $p['amount'], $p['currency'] ) ); ?></strong>
						<span class="fw-mbr-account__status"><?php echo esc_html( $labels[ $p['status'] ] ?? $p['status'] ); ?></span>
						<?php if ( '' !== FW_Membership_Invoices::url( $p ) ) : ?>
							<a href="<?php echo esc_url( FW_Membership_Invoices::url( $p ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Invoice', 'fw' ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php foreach ( array_keys( $managed ) as $gid ) : ?>
			<form method="post" class="fw-mbr-form fw-mbr-form--inline">
				<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
				<input type="hidden" name="fw_mbr_billing" value="<?php echo esc_attr( $gid ); ?>" />
				<p><?php esc_html_e( 'Card expiring or changed?', 'fw' ); ?>
					<button type="submit" class="fw-mbr-link-button"><?php esc_html_e( 'Update your payment method', 'fw' ); ?></button></p>
			</form>
		<?php endforeach; ?>
	</section>
	<?php
} );

/** "Update your payment method": off to the payment service's own page. */
add_action( 'template_redirect', function () {
	if ( empty( $_POST['fw_mbr_billing'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	check_admin_referer( FW_Membership_Accounts::NONCE );
	$g    = FW_Membership_Gateways::get( sanitize_key( wp_unslash( $_POST['fw_mbr_billing'] ) ) );
	$back = wp_get_referer() ? wp_get_referer() : FW_Membership_Settings::account_url();
	$url  = $g ? $g->manage_url( wp_get_current_user(), $back ) : '';
	if ( is_string( $url ) && '' !== $url ) {
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- the payment service's page.
		exit;
	}
	wp_safe_redirect( add_query_arg( 'fw_mbr_notice', 'failed', $back ) );
	exit;
}, 1 );
