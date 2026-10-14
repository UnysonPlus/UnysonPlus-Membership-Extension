<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * A "Membership" tab on every page-builder element, section and column, present only
 * while this extension is active.
 *
 * The tab is injected through `fw_shortcode_get_options` into every element that uses
 * the shared Advanced tab (those are the builder elements), placed just before
 * Advanced. It holds who sees the element, the plans that choice refers to, and what
 * people who may not see it get instead (nothing, or the members prompt). The decision
 * comes from FW_Membership_Access, so "members of Gold" means the same on an element as
 * in an access rule.
 *
 * Why a gate of our own as well as the `fw_sc_display_conditions` filter: the shared
 * display-conditions gate returns early (for speed) when an element sets none of ITS
 * conditions, so an element that only says "members only" would never reach that
 * filter. Our gate does the same cheap check on `mbr_show` and decodes only when set.
 *
 * The option ids (mbr_show, mbr_plans, mbr_denied) are stable: values saved while the
 * fields lived in the Advanced tab (1.0.2) keep working, since values are keyed by id.
 */
class FW_Membership_Elements {

	public static function init() {
		add_filter( 'fw_shortcode_get_options', array( __CLASS__, '_filter_shortcode_options' ), 20, 2 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, '_filter_gate' ), 11, 3 );
		add_filter( 'fw_sc_display_conditions', array( __CLASS__, '_filter_display_conditions' ), 10, 2 );
	}

	/**
	 * The tab's fields.
	 *
	 * @return array
	 */
	public static function fields() {
		$plans = array();
		foreach ( FW_Membership_Plans::all() as $p ) {
			$plans[ (string) $p['id'] ] = $p['name'];
		}
		return array(
			'group_membership' => array(
				'type'    => 'group',
				'options' => array(
					'mbr_show'   => array(
						'type'    => 'select',
						'label'   => __( 'Show to', 'fw' ),
						'desc'    => __( 'Who sees this element. Administrators see members-only elements; use "View as" in the admin bar on the site to preview what others see.', 'fw' ),
						'value'   => 'everyone',
						'choices' => FW_Membership_Access::visibility_choices(),
					),
					'mbr_plans'  => array(
						'type'    => 'checkboxes',
						'label'   => __( 'Plans', 'fw' ),
						'desc'    => $plans ? __( 'Used by "Members of the plans below" and "Everyone except members of the plans below". A plan also counts for the plans it includes.', 'fw' ) : __( 'Create plans under Unyson+ → Membership first.', 'fw' ),
						'value'   => array(),
						'choices' => $plans,
					),
					'mbr_denied' => array(
						'type'    => 'select',
						'label'   => __( 'When hidden', 'fw' ),
						'desc'    => __( 'What people who may not see the element get in its place.', 'fw' ),
						'value'   => 'nothing',
						'choices' => array(
							'nothing' => __( 'Nothing', 'fw' ),
							'prompt'  => __( 'The members prompt (log in, or the plans that unlock it)', 'fw' ),
						),
					),
				),
			),
		);
	}

	/**
	 * @internal Adds the Membership tab before Advanced on every builder element.
	 *
	 * @param mixed  $options
	 * @param string $tag
	 *
	 * @return mixed
	 */
	public static function _filter_shortcode_options( $options, $tag ) {
		if ( ! is_array( $options ) || isset( $options['tab_membership'] ) || ! self::has_tabs( $options ) || ! self::has_advanced( $options ) ) {
			return $options;
		}
		$tab = array(
			'tab_membership' => array(
				'title'   => __( 'Membership', 'fw' ),
				'type'    => 'tab',
				'options' => self::fields(),
			),
		);
		if ( ! isset( $options['tab_advanced'] ) ) {
			return $options + $tab;
		}
		$out = array();
		foreach ( $options as $key => $value ) {
			if ( 'tab_advanced' === $key ) {
				$out += $tab;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/** A top level made of tabs (a plain option list is left alone). */
	private static function has_tabs( array $options ) {
		foreach ( $options as $value ) {
			if ( is_array( $value ) && 'tab' === ( $value['type'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does it carry the shared Advanced tab (sc_get_advanced_tab)? That is what marks a
	 * builder element; its display-conditions field is the fingerprint.
	 */
	private static function has_advanced( array $options, $depth = 0 ) {
		if ( $depth > 6 ) {
			return false;
		}
		foreach ( $options as $key => $value ) {
			if ( 'dc_logged' === $key ) {
				return true;
			}
			if ( is_array( $value ) && self::has_advanced( $value, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The plan ids a checkboxes value holds ({ "3": true } or [ "3" ]).
	 *
	 * @param mixed $value
	 *
	 * @return int[]
	 */
	public static function plan_ids( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( html_entity_decode( $value, ENT_QUOTES ), true );
			$value   = is_array( $decoded ) ? $decoded : array_map( 'trim', explode( ',', $value ) );
		}
		$value = (array) $value;
		// A list ([ "3", "5" ]) holds the ids; a map ({ "3": true, "5": false }) holds them as keys.
		if ( array_values( $value ) === $value ) {
			$ids = array_map( 'intval', $value );
		} else {
			$ids = array();
			foreach ( $value as $k => $v ) {
				if ( filter_var( $v, FILTER_VALIDATE_BOOLEAN ) ) {
					$ids[] = (int) $k;
				}
			}
		}
		return array_values( array_filter( $ids ) );
	}

	/**
	 * @param array $atts Decoded atts.
	 *
	 * @return bool
	 */
	public static function visible( array $atts ) {
		$show = isset( $atts['mbr_show'] ) ? (string) $atts['mbr_show'] : 'everyone';
		if ( '' === $show || 'everyone' === $show ) {
			return true;
		}
		return FW_Membership_Access::visible_to_current( $show, self::plan_ids( $atts['mbr_plans'] ?? array() ) );
	}

	/**
	 * What replaces an element someone may not see.
	 *
	 * @param array $atts
	 *
	 * @return string
	 */
	public static function hidden_output( array $atts ) {
		if ( 'prompt' !== ( $atts['mbr_denied'] ?? 'nothing' ) ) {
			return '';
		}
		$show = (string) ( $atts['mbr_show'] ?? '' );
		if ( ! in_array( $show, array( 'members', 'plans', 'logged_in' ), true ) ) {
			return ''; // a prompt to "join" makes no sense for content meant for non-members
		}
		return FW_Membership_Denied::prompt( array(
			'id'       => 0,
			'priority' => 0,
			'who'      => array( 'plans' => 'plans' === $show ? self::plan_ids( $atts['mbr_plans'] ?? array() ) : array(), 'roles' => array(), 'logged_in' => 'logged_in' === $show ),
			'denied'   => array( 'action' => 'prompt', 'message' => '', 'redirect' => '', 'teaser_words' => 0, 'hide' => false ),
		) );
	}

	/** @internal */
	public static function _filter_gate( $output, $tag, $attr ) {
		if ( ! is_array( $attr ) || ! isset( $attr['_made_with_builder'] ) || '' === (string) $output ) {
			return $output;
		}
		$raw = isset( $attr['mbr_show'] ) ? html_entity_decode( (string) $attr['mbr_show'], ENT_QUOTES ) : '';
		if ( '' === $raw || 'everyone' === $raw ) {
			return $output;
		}
		try {
			$atts = array(
				'mbr_show'   => $raw,
				'mbr_denied' => isset( $attr['mbr_denied'] ) ? html_entity_decode( (string) $attr['mbr_denied'], ENT_QUOTES ) : 'nothing',
			);
			if ( in_array( $raw, array( 'plans', 'not_plans' ), true ) && function_exists( 'fw_ext_shortcodes_decode_attr' ) ) {
				global $post;
				$decoded = fw_ext_shortcodes_decode_attr( $attr, (string) $tag, $post ? (int) $post->ID : 0 );
				if ( is_array( $decoded ) ) {
					$atts = array_merge( $atts, $decoded );
				}
			}
			return self::visible( $atts ) ? $output : self::hidden_output( $atts );
		} catch ( \Throwable $e ) {
			return $output; // fail open, like the shared gate
		}
	}

	/** @internal Keeps sc_eval_display_conditions() in agreement for code that calls it directly. */
	public static function _filter_display_conditions( $show, $atts ) {
		return $show && is_array( $atts ) ? self::visible( $atts ) : $show;
	}
}
