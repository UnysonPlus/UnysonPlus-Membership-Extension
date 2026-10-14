<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$fw_mbr_switch = function ( $label, $desc, $value ) {
	return array(
		'type'         => 'switch',
		'label'        => $label,
		'desc'         => $desc,
		'value'        => $value,
		'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
		'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
	);
};

$options = array(
	'tab_content'  => array(
		'title'   => __( 'Content', 'fw' ),
		'type'    => 'tab',
		'options' => array(
			'group_text'    => array(
				'type'    => 'group',
				'options' => array(
					'title'   => array(
						'type'  => 'text',
						'label' => __( 'Title', 'fw' ),
						'desc'  => __( 'Leave empty for the automatic wording ("This is for members", or "Your account does not include this" for someone logged in).', 'fw' ),
						'value' => '',
					),
					'message' => array(
						'type'  => 'textarea',
						'label' => __( 'Message', 'fw' ),
						'desc'  => __( 'Leave empty for the automatic wording.', 'fw' ),
						'value' => '',
					),
				),
			),
			'group_plans'   => array(
				'type'    => 'group',
				'options' => array(
					'show_plans' => $fw_mbr_switch( __( 'List the plans', 'fw' ), __( 'Name the plans that would unlock the content.', 'fw' ), 'yes' ),
					'show_login' => $fw_mbr_switch( __( 'Log-in link', 'fw' ), __( 'Show a "Log in" button to logged-out visitors; they come back to this page afterwards.', 'fw' ), 'yes' ),
				),
			),
			'group_button'  => array(
				'type'    => 'group',
				'options' => array(
					'button_text' => array(
						'type'  => 'text',
						'label' => __( 'Button text', 'fw' ),
						'value' => '',
						'desc'  => __( 'For example "See the plans". Shown when it has both text and a link.', 'fw' ),
					),
					'button_link' => array(
						'type'  => 'text',
						'label' => __( 'Button link', 'fw' ),
						'value' => '',
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
