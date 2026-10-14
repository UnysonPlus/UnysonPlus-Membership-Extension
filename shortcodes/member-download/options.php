<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$fw_mbr_files = array( '' => __( '— Choose a file —', 'fw' ) );
if ( class_exists( 'FW_Membership_Files' ) ) {
	foreach ( FW_Membership_Files::all() as $f ) {
		$fw_mbr_files[ (string) $f['id'] ] = $f['title'];
	}
}

$options = array(
	'tab_content'  => array(
		'title'   => __( 'Content', 'fw' ),
		'type'    => 'tab',
		'options' => array(
			'group_file' => array(
				'type'    => 'group',
				'options' => array(
					'file'         => array(
						'type'    => 'select',
						'label'   => __( 'File', 'fw' ),
						'desc'    => class_exists( 'FW_Membership_Files' ) ? __( 'Upload files under Membership → Downloads.', 'fw' ) : __( 'Switch on Protected downloads in Membership → Modules first.', 'fw' ),
						'choices' => $fw_mbr_files,
						'value'   => '',
					),
					'button_text'  => array(
						'type'  => 'text',
						'label' => __( 'Button text', 'fw' ),
						'desc'  => __( 'Empty: "Download" and the file name.', 'fw' ),
						'value' => '',
					),
					'show_details' => array(
						'type'         => 'switch',
						'label'        => __( 'Show the file type and size', 'fw' ),
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
