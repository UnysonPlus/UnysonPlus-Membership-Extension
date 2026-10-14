<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$cfg = array();

$cfg['page_builder'] = array(
	'title'          => __( 'Member Directory', 'fw' ),
	'description'    => __( 'A searchable directory of members who chose to be listed, with a profile page for each.', 'fw' ),
	'tab'            => __( 'Membership', 'fw' ),
	'popup_size'     => 'small',
	'title_template' => '<strong>' . __( 'Member Directory', 'fw' ) . '</strong>',
);
