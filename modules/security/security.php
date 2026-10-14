<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Account security module (Membership → Modules → Account security).
 *
 *   class-fw-membership-log.php          logins, failed logins, denied access, plan changes
 *   class-fw-membership-sessions.php     devices signed in at once, per plan
 *   class-fw-membership-magic-links.php  one-click login links by email
 *   class-fw-membership-passkeys.php     passkeys (WebAuthn)
 */

$fw_mbr_sec_dir = dirname( __FILE__ );
foreach ( array( 'log', 'sessions', 'magic-links', 'passkeys' ) as $fw_mbr_sec_part ) {
	require_once $fw_mbr_sec_dir . '/class-fw-membership-' . $fw_mbr_sec_part . '.php';
}
FW_Membership_Log::init();
FW_Membership_Sessions::init();
FW_Membership_Magic_Links::init();
FW_Membership_Passkeys::init();
