<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Membership cards module (Membership → Modules → Membership cards): a card with a
 * QR code on the account page and full screen, and a check page for staff at the door.
 */

require_once dirname( __FILE__ ) . '/class-fw-membership-cards.php';
FW_Membership_Cards::init();
