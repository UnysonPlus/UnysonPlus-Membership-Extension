<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Member directory module (Membership → Modules → Member directory): the Member
 * Directory element, a "show me in the directory" choice on the account page, and a
 * per-field privacy setting in Profile fields.
 */

require_once dirname( __FILE__ ) . '/class-fw-membership-directory.php';
FW_Membership_Directory::init();
