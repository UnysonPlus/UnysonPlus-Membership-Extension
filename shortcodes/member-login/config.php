<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$cfg = array();

$cfg['page_builder'] = array(
	'title'          => __( 'Member Login', 'fw' ),
	'description'    => __( 'A login form in your site\'s style, with "forgot your password" and a link to sign up. Logged-in members see a short "logged in as" line instead.', 'fw' ),
	'tab'            => __( 'Membership', 'fw' ),
	'popup_size'     => 'medium',
	'title_template' => '<strong>' . __( 'Member Login', 'fw' ) . '</strong>{{ if ( o["title"] ) { }}: {{- o["title"] }}{{ } }}',
);
