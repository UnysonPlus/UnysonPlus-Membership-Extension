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
			'group_login'    => array(
				'type'    => 'group',
				'options' => array(
					'title'         => array(
						'type'  => 'text',
						'label' => __( 'Title', 'fw' ),
						'value' => __( 'Log in', 'fw' ),
					),
					'show_remember' => $fw_mbr_switch( __( '"Remember me"', 'fw' ), __( 'Offer to stay logged in on this device.', 'fw' ), 'yes' ),
					'show_lost'     => $fw_mbr_switch( __( '"Forgot your password?"', 'fw' ), __( 'Link to the password reset.', 'fw' ), 'yes' ),
				),
			),
			'group_redirect' => array(
				'type'    => 'group',
				'options' => array(
					'redirect' => array(
						'type'    => 'select',
						'label'   => __( 'After logging in', 'fw' ),
						'value'   => 'plan',
						'choices' => array(
							'plan' => __( 'Where the member\'s plan says (else the account page)', 'fw' ),
							'here' => __( 'Stay on this page', 'fw' ),
							'url'  => __( 'Go to an address', 'fw' ),
						),
					),
					'redirect_url' => array(
						'type'  => 'text',
						'label' => __( 'Address', 'fw' ),
						'value' => '',
					),
				),
			),
			'group_register' => array(
				'type'    => 'group',
				'options' => array(
					'register_text' => array(
						'type'  => 'text',
						'label' => __( 'Sign-up link text', 'fw' ),
						'value' => __( 'Not a member yet? Join', 'fw' ),
					),
					'register_link' => array(
						'type'  => 'text',
						'label' => __( 'Sign-up page', 'fw' ),
						'desc'  => __( 'The page with your sign-up form. Leave empty to hide the link.', 'fw' ),
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
