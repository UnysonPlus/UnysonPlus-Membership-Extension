<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$fw_mbr_courses = array( '' => __( '— Choose a course —', 'fw' ) );
if ( post_type_exists( 'fw_course' ) ) {
	foreach ( get_posts( array( 'post_type' => 'fw_course', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $c ) {
		$fw_mbr_courses[ (string) $c->ID ] = get_the_title( $c );
	}
}

$options = array(
	'tab_content'  => array(
		'title'   => __( 'Content', 'fw' ),
		'type'    => 'tab',
		'options' => array(
			'group_course' => array(
				'type'    => 'group',
				'options' => array(
					'course'     => array(
						'type'    => 'select',
						'label'   => __( 'Course', 'fw' ),
						'desc'    => post_type_exists( 'fw_course' ) ? __( 'Courses are made under Courses in the admin menu.', 'fw' ) : __( 'Switch on Courses in Membership → Modules first.', 'fw' ),
						'choices' => $fw_mbr_courses,
						'value'   => '',
					),
					'show_title' => array(
						'type'         => 'switch',
						'label'        => __( 'Show the course title', 'fw' ),
						'value'        => 'yes',
						'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
						'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
					),
				),
			),
		),
	),
	'tab_advanced' => array(
		'title'   => __( 'Advanced', 'fw' ),
		'type'    => 'tab',
		'options' => array(
			'advanced_settings' => array(
				'type'    => 'group',
				'options' => function_exists( 'sc_get_advanced_tab' ) ? sc_get_advanced_tab() : array(),
			),
		),
	),
);
