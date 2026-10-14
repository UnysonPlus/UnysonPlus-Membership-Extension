<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$options = array(
	'tab_content'  => array(
		'title'   => __( 'Content', 'fw' ),
		'type'    => 'tab',
		'options' => array(
			'group_dir' => array(
				'type'    => 'group',
				'options' => array(
					'per_page'     => array(
						'type'  => 'short-text',
						'label' => __( 'People per page', 'fw' ),
						'value' => '24',
					),
					'show_search'  => array(
						'type'         => 'switch',
						'label'        => __( 'Search box', 'fw' ),
						'value'        => 'yes',
						'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
						'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
					),
					'show_filters' => array(
						'type'         => 'switch',
						'label'        => __( 'Filters', 'fw' ),
						'desc'         => __( 'A filter for each choice field the visitor may see.', 'fw' ),
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
