<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$options = array(
	'tab_content'  => array(
		'title'   => __( 'Content', 'fw' ),
		'type'    => 'tab',
		'options' => array(
			'group_text' => array(
				'type'    => 'group',
				'options' => array(
					'button_text'   => array(
						'type'  => 'text',
						'label' => __( 'Pay button', 'fw' ),
						'value' => __( 'Continue to payment', 'fw' ),
					),
					'free_text'     => array(
						'type'  => 'text',
						'label' => __( 'Button for a free plan', 'fw' ),
						'value' => __( 'Join for free', 'fw' ),
					),
					'thanks_text'   => array(
						'type'  => 'textarea',
						'label' => __( 'After a successful payment', 'fw' ),
						'value' => __( 'Thank you! Your membership is active.', 'fw' ),
					),
					'no_plan_text'  => array(
						'type'  => 'text',
						'label' => __( 'When no plan was chosen', 'fw' ),
						'value' => __( 'Choose a plan to get started.', 'fw' ),
					),
					'plans_link'    => array(
						'type'  => 'text',
						'label' => __( 'Plans page', 'fw' ),
						'desc'  => __( 'Linked when no plan was chosen.', 'fw' ),
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
