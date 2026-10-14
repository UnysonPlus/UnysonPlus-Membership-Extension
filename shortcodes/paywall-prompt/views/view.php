<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}
/**
 * Paywall Prompt — the same box FW_Membership_Denied shows in place of protected
 * content, placed by hand. In scope: $atts.
 */

if ( ! class_exists( 'FW_Membership_Denied' ) ) {
	return;
}

$atts['base_class']       = 'fw-mbr-paywall';
$atts['unique_id_prefix'] = 'pw-';
$attr                     = function_exists( 'sc_build_wrapper_attr' ) ? sc_build_wrapper_attr( $atts ) : array( 'class' => 'fw-mbr-paywall' );

$yes = static function ( $v, $default ) {
	return null === $v || '' === $v ? $default : ( 'yes' === $v || true === $v );
};
?>
<div <?php echo fw_attr_to_html( $attr ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by FW_Membership_Denied::prompt().
	echo FW_Membership_Denied::prompt( null, array(
		'title'       => isset( $atts['title'] ) ? (string) $atts['title'] : '',
		'message'     => isset( $atts['message'] ) ? (string) $atts['message'] : '',
		'show_plans'  => $yes( $atts['show_plans'] ?? null, true ),
		'show_login'  => $yes( $atts['show_login'] ?? null, true ),
		'button_text' => isset( $atts['button_text'] ) ? (string) $atts['button_text'] : '',
		'button_link' => isset( $atts['button_link'] ) ? (string) $atts['button_link'] : '',
	) );
	?>
</div>
