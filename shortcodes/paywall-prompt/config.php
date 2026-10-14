<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$cfg = array();

$cfg['page_builder'] = array(
	'title'          => __( 'Paywall Prompt', 'fw' ),
	'description'    => __( 'The "this is for members" box: a message, the plans that unlock the content, a log-in link and your own button. Usually set to show only to people without a plan.', 'fw' ),
	'tab'            => __( 'Membership', 'fw' ),
	'popup_size'     => 'medium',
	'title_template' => '<strong>' . __( 'Paywall Prompt', 'fw' ) . '</strong>{{ if ( o["title"] ) { }}: {{- o["title"] }}{{ } }}',
);
