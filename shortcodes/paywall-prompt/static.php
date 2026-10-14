<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

// The prompt's styles live with the extension, shared with the box shown in place of
// protected content.
if ( class_exists( 'FW_Membership_Denied' ) ) {
	FW_Membership_Denied::enqueue();
}
