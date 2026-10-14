<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}
/**
 * Member Login. In scope: $atts.
 */
if ( ! class_exists( 'FW_Membership_Accounts' ) ) {
	return;
}
FW_Membership_Denied::enqueue();

$atts['base_class']       = 'fw-mbr-login-box';
$atts['unique_id_prefix'] = 'ml-';
$attr                     = function_exists( 'sc_build_wrapper_attr' ) ? sc_build_wrapper_attr( $atts ) : array( 'class' => 'fw-mbr-login-box' );
$yes                      = static function ( $v ) {
	return null === $v || '' === $v || 'yes' === $v || true === $v;
};
$title    = isset( $atts['title'] ) ? (string) $atts['title'] : '';
$redirect = 'plan';
if ( 'here' === ( $atts['redirect'] ?? 'plan' ) ) {
	$redirect = ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
} elseif ( 'url' === ( $atts['redirect'] ?? '' ) && ! empty( $atts['redirect_url'] ) ) {
	$redirect = (string) $atts['redirect_url'];
}
?>
<div <?php echo fw_attr_to_html( $attr ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php echo FW_Membership_Accounts::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php if ( is_user_logged_in() ) : ?>
		<?php $u = wp_get_current_user(); ?>
		<p class="fw-mbr-login__in">
			<?php echo esc_html( sprintf( __( 'You are logged in as %s.', 'fw' ), $u->display_name ) ); ?>
			<a href="<?php echo esc_url( FW_Membership_Settings::account_url() ); ?>"><?php esc_html_e( 'Your account', 'fw' ); ?></a>
			<span aria-hidden="true">·</span>
			<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log out', 'fw' ); ?></a>
		</p>
	<?php else : ?>
		<?php if ( '' !== $title ) : ?>
			<h2 class="fw-mbr-login__title"><?php echo esc_html( $title ); ?></h2>
		<?php endif; ?>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput -- built by wp_login_form() and FW_Membership_Accounts.
		echo FW_Membership_Accounts::login_form( array(
			'redirect'      => $redirect,
			'show_remember' => $yes( $atts['show_remember'] ?? null ),
			'show_lost'     => $yes( $atts['show_lost'] ?? null ),
			'register_text' => (string) ( $atts['register_text'] ?? '' ),
			'register_link' => (string) ( $atts['register_link'] ?? '' ),
		) );
		?>
	<?php endif; ?>
</div>
