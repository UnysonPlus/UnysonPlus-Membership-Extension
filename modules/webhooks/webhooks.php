<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Webhooks module (Membership → Modules → Webhooks).
 *
 *   class-fw-membership-webhooks.php   endpoints, the event → queue → delivery worker, signing
 *   admin/                             Membership → Webhooks
 */

$fw_mbr_webhooks_dir = dirname( __FILE__ );
require_once $fw_mbr_webhooks_dir . '/class-fw-membership-webhooks.php';
FW_Membership_Webhooks::init();
if ( is_admin() ) {
	require_once $fw_mbr_webhooks_dir . '/admin/class-fw-membership-admin-webhooks.php';
	FW_Membership_Admin_Webhooks::init();
}

add_filter( 'unysonplus_membership_nav', function ( $nav ) {
	$nav['setup']['items']['webhooks'] = array( 'label' => __( 'Webhooks', 'fw' ), 'icon' => 'dashicons-rest-api' );
	return $nav;
} );
