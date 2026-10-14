<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The same Membership choices on WordPress's own blocks in the block editor: a
 * "Membership" panel in the block sidebar (static/js/blocks.js), stored as two block
 * attributes and applied when the block renders.
 *
 *   fwMbrShow    everyone|members|non_members|plans|not_plans|logged_in|logged_out
 *   fwMbrPlans   int[] plan ids
 *   fwMbrDenied  nothing|prompt
 *
 * Unyson+'s own blocks (unysonplus/*) are skipped: they carry the Membership tab of the
 * element they wrap, so the choice lives in one place.
 */
class FW_Membership_Blocks {

	public static function init() {
		add_filter( 'register_block_type_args', array( __CLASS__, '_filter_block_args' ), 10, 2 );
		add_filter( 'render_block', array( __CLASS__, '_filter_render' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, '_action_editor_assets' ) );
		add_filter( 'block_editor_settings_all', array( __CLASS__, '_filter_editor_settings' ) );
	}

	/**
	 * A dashed outline on restricted blocks inside the editor canvas (the canvas is an
	 * iframe, so the style goes in through the editor settings).
	 *
	 * @internal
	 */
	public static function _filter_editor_settings( $settings ) {
		$settings['styles']   = isset( $settings['styles'] ) ? (array) $settings['styles'] : array();
		$settings['styles'][] = array( 'css' => '.fw-mbr-block-restricted{outline:1px dashed rgba(120,90,220,.55);outline-offset:4px}' );
		return $settings;
	}

	/**
	 * Server-side blocks validate their attributes, so declare ours on every block type.
	 *
	 * @internal
	 */
	public static function _filter_block_args( $args, $name ) {
		if ( 0 === strpos( (string) $name, 'unysonplus/' ) ) {
			return $args;
		}
		$args['attributes']                = isset( $args['attributes'] ) && is_array( $args['attributes'] ) ? $args['attributes'] : array();
		$args['attributes']['fwMbrShow']   = array( 'type' => 'string', 'default' => 'everyone' );
		$args['attributes']['fwMbrPlans']  = array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'number' ) );
		$args['attributes']['fwMbrDenied'] = array( 'type' => 'string', 'default' => 'nothing' );
		return $args;
	}

	/** @internal */
	public static function _filter_render( $content, $block ) {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$show  = isset( $attrs['fwMbrShow'] ) ? (string) $attrs['fwMbrShow'] : '';
		if ( '' === $show || 'everyone' === $show || is_admin() ) {
			return $content;
		}
		$atts = array(
			'mbr_show'   => $show,
			'mbr_plans'  => array_map( 'intval', (array) ( $attrs['fwMbrPlans'] ?? array() ) ),
			'mbr_denied' => (string) ( $attrs['fwMbrDenied'] ?? 'nothing' ),
		);
		return FW_Membership_Elements::visible( $atts ) ? $content : FW_Membership_Elements::hidden_output( $atts );
	}

	/** @internal */
	public static function _action_editor_assets() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$ext = fw_ext( 'membership' );
		wp_enqueue_script(
			'fw-membership-blocks',
			$ext->get_uri() . '/static/js/blocks.js',
			array( 'wp-hooks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-compose', 'wp-i18n' ),
			$ext->manifest->get_version(),
			true
		);
		$plans = array();
		foreach ( FW_Membership_Plans::all() as $p ) {
			$plans[] = array( 'id' => $p['id'], 'name' => $p['name'] );
		}
		$choices = array();
		foreach ( FW_Membership_Access::visibility_choices() as $value => $label ) {
			$choices[] = array( 'value' => $value, 'label' => $label );
		}
		wp_localize_script( 'fw-membership-blocks', 'fwMembershipBlocks', array(
			'plans'   => $plans,
			'choices' => $choices,
			'l10n'    => array(
				'panel'      => __( 'Membership', 'fw' ),
				'show'       => __( 'Show to', 'fw' ),
				'plans'      => __( 'Plans', 'fw' ),
				'noPlans'    => __( 'Create plans under Unyson+ → Membership first.', 'fw' ),
				'denied'     => __( 'When hidden', 'fw' ),
				'nothing'    => __( 'Nothing', 'fw' ),
				'prompt'     => __( 'The members prompt', 'fw' ),
				'restricted' => __( 'Shown only to some visitors (Membership).', 'fw' ),
			),
		) );
	}
}
