<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * [member_content] — show or hide part of any content by membership, for text written
 * outside the page builder (the block or classic editor, text widgets, other builders).
 *
 *   [member_content]For members of any plan.[/member_content]
 *   [member_content plans="gold,silver"]For Gold or Silver members.[/member_content]
 *   [member_content show="non_members"]Join today![/member_content]
 *   [member_content plans="gold" prompt="yes"]Gold only; others see the members prompt.[/member_content]
 *   [member_content plans="gold" else="Upgrade to Gold to read this."]…[/member_content]
 *
 *   show    everyone|members|non_members|plans|not_plans|logged_in|logged_out
 *           (default: "plans" when plans are given, otherwise "members")
 *   plans   plan slugs or ids, comma separated
 *   prompt  yes = people who may not see it get the members prompt instead
 *   else    plain text shown instead (when prompt is not used)
 *
 * Registered as `member_content` unless another plugin already owns that name, and
 * always as `upw_member_content`, which never collides.
 */
class FW_Membership_Shortcode {

	public static function init() {
		add_action( 'init', array( __CLASS__, '_action_register' ), 20 );
	}

	/** @internal */
	public static function _action_register() {
		if ( ! shortcode_exists( 'member_content' ) ) {
			add_shortcode( 'member_content', array( __CLASS__, 'render' ) );
		}
		add_shortcode( 'upw_member_content', array( __CLASS__, 'render' ) );
	}

	/**
	 * Plan slugs or ids → ids.
	 *
	 * @param string $list
	 *
	 * @return int[]
	 */
	public static function plan_ids( $list ) {
		$ids = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $list ) ) ) as $token ) {
			if ( ctype_digit( $token ) ) {
				$ids[] = (int) $token;
				continue;
			}
			$plan = FW_Membership_Plans::get_by_slug( sanitize_title( $token ) );
			if ( $plan ) {
				$ids[] = $plan['id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array|string $atts
	 * @param string|null  $content
	 *
	 * @return string
	 */
	public static function render( $atts, $content = null ) {
		$atts  = shortcode_atts( array( 'show' => '', 'plans' => '', 'prompt' => '', 'else' => '' ), (array) $atts );
		$plans = self::plan_ids( $atts['plans'] );
		$show  = sanitize_key( $atts['show'] );
		if ( '' === $show ) {
			$show = $plans ? 'plans' : 'members';
		}
		if ( ! isset( FW_Membership_Access::visibility_choices()[ $show ] ) ) {
			$show = 'members';
		}

		if ( FW_Membership_Access::visible_to_current( $show, $plans ) ) {
			return do_shortcode( (string) $content );
		}
		if ( in_array( strtolower( (string) $atts['prompt'] ), array( 'yes', '1', 'true' ), true ) ) {
			return FW_Membership_Elements::hidden_output( array(
				'mbr_show'   => $show,
				'mbr_plans'  => $plans,
				'mbr_denied' => 'prompt',
			) );
		}
		return '' !== $atts['else'] ? '<p class="fw-mbr-else">' . esc_html( $atts['else'] ) . '</p>' : '';
	}
}
