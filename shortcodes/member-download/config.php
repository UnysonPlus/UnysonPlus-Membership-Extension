<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$cfg = array();

$cfg['page_builder'] = array(
	'title'          => __( 'Member Download', 'fw' ),
	'description'    => __( 'A download button for a protected file (Membership → Downloads). People who may not have it see why, with a way to log in or join.', 'fw' ),
	'tab'            => __( 'Membership', 'fw' ),
	'popup_size'     => 'small',
	'title_template' => '<strong>' . __( 'Member Download', 'fw' ) . '</strong>',
);
