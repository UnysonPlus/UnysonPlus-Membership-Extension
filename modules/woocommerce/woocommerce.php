<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The WooCommerce module (Membership → Modules → WooCommerce; needs WooCommerce).
 *
 *   class-fw-membership-woocommerce.php  products that grant plans (orders, refunds, subscriptions)
 *   class-fw-membership-woo-perks.php    member discounts, free shipping, members-only buying
 */

if ( ! class_exists( 'WooCommerce' ) ) {
	return;
}
require_once dirname( __FILE__ ) . '/class-fw-membership-woocommerce.php';
require_once dirname( __FILE__ ) . '/class-fw-membership-woo-perks.php';
FW_Membership_WooCommerce::init();
FW_Membership_Woo_Perks::init();
