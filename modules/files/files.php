<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Protected downloads module (Membership → Modules → Protected downloads).
 *
 *   class-fw-membership-files.php   storage, access, limits, the download log, serving
 *   admin/                          Membership → Downloads
 *
 * The Member Download element (shortcodes/member-download) puts a file on a page.
 */

$fw_mbr_files_dir = dirname( __FILE__ );
require_once $fw_mbr_files_dir . '/class-fw-membership-files.php';
FW_Membership_Files::init();
if ( is_admin() ) {
	require_once $fw_mbr_files_dir . '/admin/class-fw-membership-admin-files.php';
	FW_Membership_Admin_Files::init();
}

add_filter( 'unysonplus_membership_nav', function ( $nav ) {
	$nav['main']['items']['files'] = array( 'label' => __( 'Downloads', 'fw' ), 'icon' => 'dashicons-download' );
	return $nav;
} );
