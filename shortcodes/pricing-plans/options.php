<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$fw_mbr_plans = array();
$fw_mbr_feat  = array( '' => __( 'None', 'fw' ) );
if ( class_exists( 'FW_Membership_Plans' ) ) {
	foreach ( FW_Membership_Plans::all( 'active' ) as $p ) {
		if ( FW_Membership_Plans::is_item( $p ) ) {
			continue;
		}
		$fw_mbr_plans[ (string) $p['id'] ] = $p['name'];
		$fw_mbr_feat[ (string) $p['id'] ]  = $p['name'];
	}
}

$options = array(
	'tab_content'  => array(
		'title'   => __( 'Content', 'fw' ),
		'type'    => 'tab',
		'options' => array(
			'group_plans'  => array(
				'type'    => 'group',
				'options' => array(
					'plans'    => array(
						'type'    => 'checkboxes',
						'label'   => __( 'Plans', 'fw' ),
						'desc'    => __( 'None ticked: every open plan, highest priority last.', 'fw' ),
						'value'   => array(),
						'choices' => $fw_mbr_plans,
					),
					'featured' => array(
						'type'    => 'select',
						'label'   => __( 'Highlight', 'fw' ),
						'value'   => '',
						'choices' => $fw_mbr_feat,
					),
					'featured_text' => array(
						'type'  => 'text',
						'label' => __( 'Highlight label', 'fw' ),
						'value' => __( 'Most popular', 'fw' ),
					),
				),
			),
			'group_labels' => array(
				'type'    => 'group',
				'options' => array(
					'button_text'  => array(
						'type'  => 'text',
						'label' => __( 'Button text', 'fw' ),
						'value' => __( 'Join', 'fw' ),
					),
					'gift_text'    => array(
						'type'  => 'text',
						'label' => __( 'Gift link text', 'fw' ),
						'desc'  => __( 'The link under each paid plan to buy it for someone else (Gifts module). Leave empty to hide it.', 'fw' ),
						'value' => __( 'Give as a gift', 'fw' ),
					),
					'current_text' => array(
						'type'  => 'text',
						'label' => __( 'Text for the plan a member already holds', 'fw' ),
						'value' => __( 'Your plan', 'fw' ),
					),
					'change_text'  => array(
						'type'  => 'text',
						'label' => __( 'Button for a plan the member can switch to', 'fw' ),
						'desc'  => __( 'Takes them to their account page, where they change plan instead of buying a second one.', 'fw' ),
						'value' => __( 'Change plan', 'fw' ),
					),
					'monthly_text' => array(
						'type'  => 'text',
						'label' => __( '"Monthly" switch label', 'fw' ),
						'value' => __( 'Monthly', 'fw' ),
					),
					'yearly_text'  => array(
						'type'  => 'text',
						'label' => __( '"Yearly" switch label', 'fw' ),
						'value' => __( 'Yearly', 'fw' ),
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
