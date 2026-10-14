<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$cfg = array();

$cfg['page_builder'] = array(
	'title'          => __( 'Membership Checkout', 'fw' ),
	'description'    => __( 'The checkout: the chosen plan, an account for new buyers, and the ways to pay. Put it on one page and choose that page in Membership → Payments → Payment settings.', 'fw' ),
	'tab'            => __( 'Membership', 'fw' ),
	'popup_size'     => 'medium',
	'title_template' => '<strong>' . __( 'Membership Checkout', 'fw' ) . '</strong>',
);
