<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}
/**
 * Member Account. In scope: $atts. The forms post back to this page and are handled by
 * FW_Membership_Accounts before any output.
 */
if ( ! class_exists( 'FW_Membership_Accounts' ) ) {
	return;
}
FW_Membership_Denied::enqueue();

$atts['base_class']       = 'fw-mbr-account';
$atts['unique_id_prefix'] = 'ma-';
$attr                     = function_exists( 'sc_build_wrapper_attr' ) ? sc_build_wrapper_attr( $atts ) : array( 'class' => 'fw-mbr-account' );
$attr['id']               = 'fw-mbr-account';
$on                       = static function ( $v ) {
	return null === $v || '' === $v || 'yes' === $v || true === $v;
};
?>
<div <?php echo fw_attr_to_html( $attr ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php echo FW_Membership_Accounts::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

	<?php if ( ! is_user_logged_in() ) : ?>
		<?php
		/** A step for visitors that replaces the login form (the Groups module's team invitation). */
		$guest = (string) apply_filters( 'unysonplus_membership_account_guest_takeover', '' );
		?>
		<?php if ( '' !== $guest ) : ?>
			<?php echo $guest; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php else : ?>
			<h2 class="fw-mbr-login__title"><?php esc_html_e( 'Log in to your account', 'fw' ); ?></h2>
			<?php echo FW_Membership_Accounts::login_form(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php endif; ?>
	<?php else : ?>
		<?php
		$u     = wp_get_current_user();
		$mines = FW_Membership_Memberships::query( array( 'user_id' => $u->ID, 'per_page' => 50, 'orderby' => 'started_at' ) );
		$mines = array_values( array_filter( $mines, function ( $m ) {
			return 'pending' !== $m['status'];
		} ) );
		?>
		<header class="fw-mbr-account__head">
			<p class="fw-mbr-account__hello"><?php echo esc_html( sprintf( __( 'Hello, %s', 'fw' ), '' !== $u->first_name ? $u->first_name : $u->display_name ) ); ?></p>
			<a class="fw-mbr-account__logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log out', 'fw' ); ?></a>
		</header>
		<?php
		/** A step that takes over the account page (the Retention module's cancellation flow). */
		$takeover = (string) apply_filters( 'unysonplus_membership_account_takeover', '', $u );
		if ( '' !== $takeover ) {
			echo $takeover; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';
			return;
		}
		?>

		<?php if ( $on( $atts['show_memberships'] ?? null ) ) : ?>
			<section class="fw-mbr-account__section" aria-labelledby="fw-mbr-acc-ms">
				<h2 id="fw-mbr-acc-ms"><?php esc_html_e( 'Your memberships', 'fw' ); ?></h2>
				<?php if ( ! $mines ) : ?>
					<p><?php esc_html_e( 'You do not have a membership yet.', 'fw' ); ?>
						<?php if ( ! empty( $atts['join_link'] ) && ! empty( $atts['join_text'] ) ) : ?>
							<a href="<?php echo esc_url( $atts['join_link'] ); ?>"><?php echo esc_html( $atts['join_text'] ); ?></a>
						<?php endif; ?>
					</p>
				<?php else : ?>
					<ul class="fw-mbr-account__plans">
						<?php foreach ( $mines as $m ) : ?>
							<?php
							$plan  = FW_Membership_Plans::get( $m['plan_id'] );
							$dfmt  = get_option( 'date_format' );
							$live  = in_array( $m['status'], FW_Membership_Memberships::ACCESS_STATUSES, true );
							$state = array(
								'active'    => __( 'Active', 'fw' ),
								'trialing'  => __( 'Trial', 'fw' ),
								'past_due'  => __( 'Payment due', 'fw' ),
								'paused'    => __( 'Paused', 'fw' ),
								'cancelled' => __( 'Cancelled', 'fw' ),
								'expired'   => __( 'Ended', 'fw' ),
								'refunded'  => __( 'Refunded', 'fw' ),
							);
							?>
							<li class="fw-mbr-account__plan<?php echo $live ? '' : ' is-ended'; ?>">
								<div class="fw-mbr-account__plan-head">
									<strong><?php echo $plan ? esc_html( $plan['name'] ) : esc_html__( 'Membership', 'fw' ); ?></strong>
									<span class="fw-mbr-account__status fw-mbr-account__status--<?php echo esc_attr( $m['status'] ); ?>"><?php echo esc_html( $state[ $m['status'] ] ?? $m['status'] ); ?></span>
								</div>
								<p class="fw-mbr-account__dates">
									<?php echo esc_html( sprintf( __( 'Since %s', 'fw' ), date_i18n( $dfmt, strtotime( (string) $m['started_at'] ) ) ) ); ?>
									·
									<?php
									if ( ! $m['ends_at'] ) {
										esc_html_e( 'No end date', 'fw' );
									} elseif ( 'paused' === $m['status'] ) {
										esc_html_e( 'On hold', 'fw' );
									} elseif ( 'cancelled' === $m['status'] ) {
										echo esc_html( sprintf( __( 'Access until %s', 'fw' ), date_i18n( $dfmt, strtotime( $m['ends_at'] ) ) ) );
									} elseif ( $live ) {
										echo esc_html( sprintf( __( 'Until %s', 'fw' ), date_i18n( $dfmt, strtotime( $m['ends_at'] ) ) ) );
									} else {
										echo esc_html( sprintf( __( 'Ended %s', 'fw' ), date_i18n( $dfmt, strtotime( $m['ends_at'] ) ) ) );
									}
									?>
								</p>
								<?php if ( FW_Membership_Settings::get( 'member_cancel' ) && $m['ends_at'] && in_array( $m['status'], array( 'active', 'trialing' ), true ) ) : ?>
									<?php ob_start(); ?>
									<form method="post" class="fw-mbr-account__cancel" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel this membership? You keep access until its end date.', 'fw' ) ); ?>');">
										<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
										<input type="hidden" name="fw_mbr_account_action" value="cancel" />
										<input type="hidden" name="membership" value="<?php echo (int) $m['id']; ?>" />
										<button type="submit" class="fw-mbr-link-button"><?php esc_html_e( 'Cancel membership', 'fw' ); ?></button>
									</form>
									<?php
									/** The cancel control for one membership (the Retention module sends it through a short flow). */
									echo apply_filters( 'unysonplus_membership_account_cancel_html', (string) ob_get_clean(), $m ); // phpcs:ignore WordPress.Security.EscapeOutput
									?>
								<?php endif; ?>
								<?php
								/** More for one membership (the Payments module adds "Change plan"). */
								do_action( 'unysonplus_membership_account_membership', $m, $u );
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php
		/** Lets modules add sections to the member's account page (Payments adds receipts). */
		do_action( 'unysonplus_membership_account_sections', $u );
		?>

		<?php if ( $on( $atts['show_profile'] ?? null ) ) : ?>
			<section class="fw-mbr-account__section" aria-labelledby="fw-mbr-acc-me">
				<h2 id="fw-mbr-acc-me"><?php esc_html_e( 'Your details', 'fw' ); ?></h2>
				<form method="post" class="fw-mbr-form">
					<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
					<input type="hidden" name="fw_mbr_account_action" value="profile" />
					<p class="fw-mbr-form__row fw-mbr-form__row--half">
						<label for="fw-mbr-first"><?php esc_html_e( 'First name', 'fw' ); ?></label>
						<input type="text" id="fw-mbr-first" name="first_name" autocomplete="given-name" value="<?php echo esc_attr( $u->first_name ); ?>" />
					</p>
					<p class="fw-mbr-form__row fw-mbr-form__row--half">
						<label for="fw-mbr-last"><?php esc_html_e( 'Last name', 'fw' ); ?></label>
						<input type="text" id="fw-mbr-last" name="last_name" autocomplete="family-name" value="<?php echo esc_attr( $u->last_name ); ?>" />
					</p>
					<p class="fw-mbr-form__row">
						<label for="fw-mbr-email"><?php esc_html_e( 'Email', 'fw' ); ?></label>
						<input type="email" id="fw-mbr-email" name="email" autocomplete="email" required value="<?php echo esc_attr( $u->user_email ); ?>" />
					</p>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by FW_Membership_Profile::inputs().
					echo FW_Membership_Profile::inputs( $u->ID, array_filter( FW_Membership_Profile::fields_for_user( $u->ID ), function ( $f ) {
						return $f['member_edit'];
					} ) );
					?>
					<p class="fw-mbr-form__row">
						<label for="fw-mbr-email-pass"><?php esc_html_e( 'Current password (only to change your email)', 'fw' ); ?></label>
						<input type="password" id="fw-mbr-email-pass" name="current_password" autocomplete="current-password" />
					</p>
					<p class="fw-mbr-form__actions"><button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class() ); ?>"><?php esc_html_e( 'Save details', 'fw' ); ?></button></p>
				</form>
			</section>
		<?php endif; ?>

		<?php if ( $on( $atts['show_security'] ?? null ) ) : ?>
			<section class="fw-mbr-account__section" aria-labelledby="fw-mbr-acc-sec">
				<h2 id="fw-mbr-acc-sec"><?php esc_html_e( 'Password and devices', 'fw' ); ?></h2>
				<form method="post" class="fw-mbr-form">
					<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
					<input type="hidden" name="fw_mbr_account_action" value="password" />
					<input type="text" name="username" value="<?php echo esc_attr( $u->user_login ); ?>" autocomplete="username" hidden />
					<p class="fw-mbr-form__row">
						<label for="fw-mbr-cur"><?php esc_html_e( 'Current password', 'fw' ); ?></label>
						<input type="password" id="fw-mbr-cur" name="current_password" autocomplete="current-password" required />
					</p>
					<p class="fw-mbr-form__row fw-mbr-form__row--half">
						<label for="fw-mbr-new"><?php esc_html_e( 'New password', 'fw' ); ?></label>
						<input type="password" id="fw-mbr-new" name="new_password" autocomplete="new-password" minlength="8" required />
					</p>
					<p class="fw-mbr-form__row fw-mbr-form__row--half">
						<label for="fw-mbr-new2"><?php esc_html_e( 'Repeat the new password', 'fw' ); ?></label>
						<input type="password" id="fw-mbr-new2" name="new_password_repeat" autocomplete="new-password" minlength="8" required />
					</p>
					<p class="fw-mbr-form__actions"><button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class() ); ?>"><?php esc_html_e( 'Change password', 'fw' ); ?></button></p>
				</form>
				<form method="post" class="fw-mbr-form fw-mbr-form--inline">
					<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
					<input type="hidden" name="fw_mbr_account_action" value="sessions" />
					<p><?php esc_html_e( 'Logged in on a device you no longer use?', 'fw' ); ?>
						<button type="submit" class="fw-mbr-link-button"><?php esc_html_e( 'Sign out everywhere else', 'fw' ); ?></button></p>
				</form>
			</section>
		<?php endif; ?>
	<?php endif; ?>
</div>
