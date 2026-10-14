<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$cfg = array();

$cfg['page_builder'] = array(
	'title'          => __( 'Pricing Plans', 'fw' ),
	'description'    => __( 'Your membership plans side by side with their prices and a button to join, with an optional monthly / yearly switch. Buttons go to your checkout page.', 'fw' ),
	'tab'            => __( 'Membership', 'fw' ),
	'popup_size'     => 'medium',
	'title_template' => '<strong>' . __( 'Pricing Plans', 'fw' ) . '</strong>',
);
