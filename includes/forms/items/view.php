<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}
/**
 * Username / Password field on the site. Same markup as Forms' text fields, so the
 * theme's form styles apply.
 *
 * @var array      $item
 * @var array      $attr
 * @var array      $options
 * @var array|null $confirm The "repeat password" box, if any.
 */
$confirm = isset( $confirm ) ? $confirm : null;
if ( ! empty( $options['info'] ) && ! empty( $attr['id'] ) ) {
	$attr['aria-describedby'] = $attr['id'] . '-info';
}
?>
<div class="<?php echo esc_attr( fw_ext_builder_get_item_width( 'form-builder', $item['width'] . '/frontend_class' ) ); ?>">
	<div class="field-text field-<?php echo esc_attr( $item['type'] ); ?>">
		<?php if ( ! empty( $options['label'] ) ) : ?>
			<label for="<?php echo esc_attr( $attr['id'] ); ?>"><?php echo fw_htmlspecialchars( $options['label'] ); ?>
				<?php if ( ! empty( $options['required'] ) ) : ?><sup>*</sup><?php endif; ?>
			</label>
		<?php endif; ?>
		<input <?php echo fw_attr_to_html( $attr ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
		<?php if ( $confirm ) : ?>
			<label for="<?php echo esc_attr( $confirm['id'] ); ?>" class="fw-mbr-field-repeat"><?php esc_html_e( 'Repeat the password', 'fw' ); ?></label>
			<input <?php echo fw_attr_to_html( $confirm ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
		<?php endif; ?>
		<?php if ( ! empty( $options['info'] ) ) : ?>
			<p class="field-info" id="<?php echo esc_attr( $attr['id'] ); ?>-info"><em><?php echo esc_html( $options['info'] ); ?></em></p>
		<?php endif; ?>
	</div>
</div>
