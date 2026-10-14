<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Coupons module (Membership → Modules → Coupons). Needs the Payments module.
 *
 *   class-fw-membership-coupons.php   table, rules, the discount in a quote, Stripe coupons
 *   admin/                            Membership → Coupons
 *
 * Buyers type a code at the checkout, or arrive with ?coupon=CODE on a checkout or
 * pricing link.
 */

$fw_mbr_cpn_dir = dirname( __FILE__ );
require_once $fw_mbr_cpn_dir . '/class-fw-membership-coupons.php';
FW_Membership_Coupons::init();
if ( is_admin() ) {
	require_once $fw_mbr_cpn_dir . '/admin/class-fw-membership-admin-coupons.php';
	FW_Membership_Admin_Coupons::init();
}

add_filter( 'unysonplus_membership_nav', function ( $nav ) {
	if ( isset( $nav['selling']['items']['coupons'] ) ) {
		unset( $nav['selling']['items']['coupons']['soon'] );
	}
	return $nav;
} );
