<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}
/**
 * Course Outline. In scope: $atts.
 */
if ( ! class_exists( 'FW_Membership_Progress' ) ) {
	if ( current_user_can( 'manage_options' ) ) {
		echo '<p class="fw-mbr-notice">' . esc_html__( 'Administrators only: switch on Courses (Membership → Modules) to use this element.', 'fw' ) . '</p>';
	}
	return;
}
$course = ! empty( $atts['course'] ) ? get_post( (int) $atts['course'] ) : null;
if ( ! $course || FW_Membership_Courses::COURSE !== $course->post_type || 'publish' !== $course->post_status ) {
	if ( current_user_can( 'manage_options' ) ) {
		echo '<p class="fw-mbr-notice">' . esc_html__( 'Administrators only: choose a published course for this Course Outline element.', 'fw' ) . '</p>';
	}
	return;
}
$atts['base_class']       = 'fw-mbr-course-outline';
$atts['unique_id_prefix'] = 'co-';
$attr                     = function_exists( 'sc_build_wrapper_attr' ) ? sc_build_wrapper_attr( $atts ) : array( 'class' => 'fw-mbr-course-outline' );
?>
<div <?php echo fw_attr_to_html( $attr ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php if ( 'no' !== ( $atts['show_title'] ?? 'yes' ) ) : ?>
		<h2 class="fw-mbr-course-outline__title"><a href="<?php echo esc_url( get_permalink( $course ) ); ?>"><?php echo esc_html( get_the_title( $course ) ); ?></a></h2>
	<?php endif; ?>
	<?php echo FW_Membership_Progress::outline_html( $course->ID ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
