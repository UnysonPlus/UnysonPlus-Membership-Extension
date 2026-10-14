<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

if ( class_exists( 'FW_Membership_Denied' ) ) {
	FW_Membership_Denied::enqueue(); // front.css holds the login and account styles too
}
