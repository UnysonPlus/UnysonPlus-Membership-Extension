<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}
/**
 * Member Directory. In scope: $atts. ?member=<user nicename> on the same page shows a
 * profile; ?q=, ?f[key]= and ?pg= search, filter and page the list.
 */
if ( ! class_exists( 'FW_Membership_Directory' ) ) {
	if ( current_user_can( 'manage_options' ) ) {
		echo '<p class="fw-mbr-notice">' . esc_html__( 'Administrators only: switch on Member directory (Membership → Modules) to use this element.', 'fw' ) . '</p>';
	}
	return;
}
FW_Membership_Denied::enqueue();
$atts['base_class']       = 'fw-mbr-directory';
$atts['unique_id_prefix'] = 'md-';
$attr                     = function_exists( 'sc_build_wrapper_attr' ) ? sc_build_wrapper_attr( $atts ) : array( 'class' => 'fw-mbr-directory' );
$here                     = get_permalink();
echo '<div ' . fw_attr_to_html( $attr ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput

if ( ! FW_Membership_Directory::can_view() ) {
	echo FW_Membership_Denied::prompt( null, array( 'title' => __( 'The member directory is for members', 'fw' ), 'message' => __( 'Log in, or join, to see who else is here.', 'fw' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div>';
	return;
}

// phpcs:disable WordPress.Security.NonceVerification -- read-only search.
$slug = isset( $_GET['member'] ) ? sanitize_title( wp_unslash( $_GET['member'] ) ) : '';
if ( '' !== $slug ) {
	$u = get_user_by( 'slug', $slug );
	if ( ! $u || ! FW_Membership_Directory::listed( $u->ID ) ) {
		echo '<p class="fw-mbr-notice">' . esc_html__( 'This member is not in the directory.', 'fw' ) . '</p>';
	} else {
		$c = FW_Membership_Directory::card( $u );
		?>
		<p><a href="<?php echo esc_url( $here ); ?>">← <?php esc_html_e( 'All members', 'fw' ); ?></a></p>
		<article class="fw-mbr-profile">
			<img class="fw-mbr-profile__avatar" src="<?php echo esc_url( $c['avatar'] ); ?>" alt="" width="120" height="120" loading="lazy" />
			<div>
				<h2 class="fw-mbr-profile__name"><?php echo esc_html( $c['name'] ); ?></h2>
				<?php if ( $c['fields'] ) : ?>
					<dl class="fw-mbr-profile__fields">
						<?php foreach ( $c['fields'] as $label => $value ) : ?>
							<dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd>
						<?php endforeach; ?>
					</dl>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}
	echo '</div>';
	return;
}

$q       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$filters = isset( $_GET['f'] ) && is_array( $_GET['f'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_GET['f'] ) ) : array();
$page    = max( 1, isset( $_GET['pg'] ) ? (int) $_GET['pg'] : 1 );
// phpcs:enable
$per     = max( 1, min( 100, (int) ( $atts['per_page'] ?? 24 ) ) );
$res     = FW_Membership_Directory::query( array( 'search' => $q, 'fields' => $filters, 'page' => $page, 'per_page' => $per ) );
$choices = array_filter( FW_Membership_Directory::visible_fields(), function ( $f ) {
	return 'select' === $f['type'] && $f['choices'];
} );
?>
	<?php if ( 'no' !== ( $atts['show_search'] ?? 'yes' ) || ( 'no' !== ( $atts['show_filters'] ?? 'yes' ) && $choices ) ) : ?>
		<form method="get" class="fw-mbr-directory__search" action="<?php echo esc_url( $here ); ?>" role="search">
			<?php if ( 'no' !== ( $atts['show_search'] ?? 'yes' ) ) : ?>
				<label class="screen-reader-text" for="fw-mbr-dir-q"><?php esc_html_e( 'Search members', 'fw' ); ?></label>
				<input type="search" id="fw-mbr-dir-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Search members', 'fw' ); ?>" />
			<?php endif; ?>
			<?php if ( 'no' !== ( $atts['show_filters'] ?? 'yes' ) ) : ?>
				<?php foreach ( $choices as $key => $f ) : ?>
					<label class="screen-reader-text" for="fw-mbr-dir-f-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $f['label'] ); ?></label>
					<select id="fw-mbr-dir-f-<?php echo esc_attr( $key ); ?>" name="f[<?php echo esc_attr( $key ); ?>]">
						<option value=""><?php echo esc_html( sprintf( __( 'Any %s', 'fw' ), strtolower( $f['label'] ) ) ); ?></option>
						<?php foreach ( $f['choices'] as $ch ) : ?>
							<option value="<?php echo esc_attr( $ch ); ?>" <?php selected( $filters[ $key ] ?? '', $ch ); ?>><?php echo esc_html( $ch ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endforeach; ?>
			<?php endif; ?>
			<button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class( true ) ); ?>"><?php esc_html_e( 'Search', 'fw' ); ?></button>
		</form>
	<?php endif; ?>

	<p class="fw-mbr-directory__count" role="status"><?php echo esc_html( sprintf( _n( '%d member', '%d members', $res['total'], 'fw' ), $res['total'] ) ); ?></p>

	<?php if ( $res['users'] ) : ?>
		<ul class="fw-mbr-directory__grid">
			<?php foreach ( $res['users'] as $u ) : ?>
				<?php $c = FW_Membership_Directory::card( $u ); ?>
				<li class="fw-mbr-directory__card">
					<a href="<?php echo esc_url( add_query_arg( 'member', $c['slug'], $here ) ); ?>">
						<img src="<?php echo esc_url( $c['avatar'] ); ?>" alt="" width="72" height="72" loading="lazy" />
						<strong><?php echo esc_html( $c['name'] ); ?></strong>
						<?php foreach ( array_slice( $c['fields'], 0, 2, true ) as $label => $value ) : ?>
							<span><?php echo esc_html( $value ); ?></span>
						<?php endforeach; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		$pages = (int) ceil( $res['total'] / $per );
		if ( $pages > 1 ) :
			?>
			<nav class="fw-mbr-directory__pages" aria-label="<?php esc_attr_e( 'Pages', 'fw' ); ?>">
				<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
					<?php if ( $i === $page ) : ?>
						<span aria-current="page"><?php echo (int) $i; ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'pg' => $i, 'q' => $q, 'f' => $filters ), $here ) ); ?>"><?php echo (int) $i; ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</nav>
		<?php endif; ?>
	<?php else : ?>
		<p><?php esc_html_e( 'Nobody matches yet.', 'fw' ); ?></p>
	<?php endif; ?>
</div>
