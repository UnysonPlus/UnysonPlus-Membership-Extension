<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$cfg = array();

$cfg['page_builder'] = array(
	'title'          => __( 'Member Account', 'fw' ),
	'description'    => __( 'The member\'s own account page: their details, their memberships (with cancel), and password and devices. Logged-out visitors see the login form. Pick its page under Membership → Settings.', 'fw' ),
	'tab'            => __( 'Membership', 'fw' ),
	'popup_size'     => 'medium',
	'title_template' => '<strong>' . __( 'Member Account', 'fw' ) . '</strong>',
);
