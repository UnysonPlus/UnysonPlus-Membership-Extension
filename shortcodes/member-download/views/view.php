<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}
/**
 * Member Download. In scope: $atts. The button goes to ?fw_mbr_download=<id>, which
 * checks access again; this view only decides what to show.
 */
if ( ! class_exists( 'FW_Membership_Files' ) ) {
	if ( current_user_can( 'manage_options' ) ) {
		echo '<p class="fw-mbr-notice">' . esc_html__( 'Administrators only: switch on Protected downloads (Membership → Modules) to use this element.', 'fw' ) . '</p>';
	}
	return;
}
$f = ! empty( $atts['file'] ) ? FW_Membership_Files::get( (int) $atts['file'] ) : null;
if ( ! $f || 'active' !== $f['status'] ) {
	if ( current_user_can( 'manage_options' ) ) {
		echo '<p class="fw-mbr-notice">' . esc_html__( 'Administrators only: choose a file for this Member Download element.', 'fw' ) . '</p>';
	}
	return;
}
FW_Membership_Denied::enqueue();

$atts['base_class']       = 'fw-mbr-download';
$atts['unique_id_prefix'] = 'dl-';
$attr                     = function_exists( 'sc_build_wrapper_attr' ) ? sc_build_wrapper_attr( $atts ) : array( 'class' => 'fw-mbr-download' );
$uid                      = get_current_user_id();
$no                       = FW_Membership_Files::refuse( $f, $uid );
$ext                      = strtoupper( (string) pathinfo( $f['original'], PATHINFO_EXTENSION ) );
$label                    = '' !== trim( (string) ( $atts['button_text'] ?? '' ) ) ? (string) $atts['button_text'] : sprintf( __( 'Download %s', 'fw' ), $f['title'] );
?>
<div <?php echo fw_attr_to_html( $attr ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php if ( ! $no ) : ?>
		<a class="<?php echo esc_attr( FW_Membership_Settings::button_class() ); ?>" href="<?php echo esc_url( FW_Membership_Files::url( $f['id'] ) ); ?>" rel="nofollow"><?php echo esc_html( $label ); ?></a>
	<?php elseif ( 'fw_mbr_file_limit' === $no->get_error_code() ) : ?>
		<p class="fw-mbr-notice" role="status"><?php echo esc_html( $no->get_error_message() ); ?></p>
	<?php else : ?>
		<span class="<?php echo esc_attr( FW_Membership_Settings::button_class( true ) ); ?>" aria-disabled="true"><?php echo esc_html( $label ); ?></span>
		<?php
		$item = class_exists( 'FW_Membership_Single_Purchase' ) ? FW_Membership_Single_Purchase::plan_for( 'file', $f['id'] ) : null;
		$buy  = $item ? FW_Membership_Gateways::checkout_url( array( 'plan' => $item['slug'], 'price' => $item['prices'][0]['id'] ) ) : '';
		?>
		<?php if ( '' !== $buy ) : ?>
			<a class="<?php echo esc_attr( FW_Membership_Settings::button_class() ); ?>" href="<?php echo esc_url( $buy ); ?>"><?php echo esc_html( sprintf( __( 'Buy it for %s', 'fw' ), FW_Membership_Plans::money( $item['prices'][0]['amount'], $item['prices'][0]['currency'] ) ) ); ?></a>
		<?php endif; ?>
		<p class="fw-mbr-download__why"><?php echo esc_html( $no->get_error_message() ); ?>
			<?php if ( ! $uid ) : ?>
				<a href="<?php echo esc_url( FW_Membership_Settings::login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log in', 'fw' ); ?></a>
			<?php endif; ?>
			<?php
			$join = '' === $buy && ! empty( $f['who']['plans'] ) ? FW_Membership_Settings::join_url( array_map( 'intval', (array) $f['who']['plans'] ) ) : '';
			if ( '' !== $join ) :
				?>
				<a href="<?php echo esc_url( $join ); ?>"><?php echo esc_html( $uid ? __( 'See the plans', 'fw' ) : __( 'Join', 'fw' ) ); ?></a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
	<?php if ( 'no' !== ( $atts['show_details'] ?? 'yes' ) ) : ?>
		<span class="fw-mbr-download__meta"><?php echo esc_html( trim( $ext . ' · ' . size_format( $f['size'] ), ' ·' ) ); ?></span>
	<?php endif; ?>
</div>
