<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Metered paywall module (Membership → Modules → Metered paywall): free reads per
 * period for visitors and free accounts, on rules with "Free reads" ticked.
 */

require_once dirname( __FILE__ ) . '/class-fw-membership-metered.php';
FW_Membership_Metered::init();
