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
			'group_sections' => array(
				'type'    => 'group',
				'options' => array(
					'show_profile'     => $fw_mbr_switch( __( 'Your details', 'fw' ), __( 'Name and email.', 'fw' ), 'yes' ),
					'show_memberships' => $fw_mbr_switch( __( 'Memberships', 'fw' ), __( 'Each plan with its status and dates, and Cancel (if Settings allow it).', 'fw' ), 'yes' ),
					'show_security'    => $fw_mbr_switch( __( 'Password and devices', 'fw' ), __( 'Change the password; sign out of other devices.', 'fw' ), 'yes' ),
				),
			),
			'group_join'     => array(
				'type'    => 'group',
				'options' => array(
					'join_text' => array(
						'type'  => 'text',
						'label' => __( '"No membership yet" link text', 'fw' ),
						'value' => __( 'See the plans', 'fw' ),
					),
					'join_link' => array(
						'type'  => 'text',
						'label' => __( '"No membership yet" link', 'fw' ),
						'desc'  => __( 'Your plans or sign-up page, shown to members without a plan. Leave empty to hide.', 'fw' ),
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
