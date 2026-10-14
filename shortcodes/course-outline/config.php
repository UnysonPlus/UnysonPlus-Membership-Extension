<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$cfg = array();

$cfg['page_builder'] = array(
	'title'          => __( 'Course Outline', 'fw' ),
	'description'    => __( 'A course’s modules and lessons with the student’s progress, and a Start / Resume button (or how to join, for people who cannot take it yet).', 'fw' ),
	'tab'            => __( 'Membership', 'fw' ),
	'popup_size'     => 'small',
	'title_template' => '<strong>' . __( 'Course Outline', 'fw' ) . '</strong>',
);
