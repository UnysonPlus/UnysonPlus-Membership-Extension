<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Drip content module (Membership → Modules → Drip content): rules that open N days
 * after joining or on a date, a "coming up" list on the account page and an email when
 * content opens.
 */

require_once dirname( __FILE__ ) . '/class-fw-membership-drip.php';
FW_Membership_Drip::init();
