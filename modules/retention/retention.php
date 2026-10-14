<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Retention module (Membership → Modules → Retention).
 *
 *   class-fw-membership-retention.php    settings (Settings → Keeping members) + the daily job
 *   class-fw-membership-dunning.php      failed payments: grace, follow-ups, card link, expiring cards
 *   class-fw-membership-pause.php        pause instead of cancel
 *   class-fw-membership-cancel-flow.php  why + one offer before cancelling
 *   class-fw-membership-reminders.php    renewal / trial / expiry reminders and win-back
 *
 * Membership → Retention shows why people leave and what kept them.
 */

$fw_mbr_ret_dir = dirname( __FILE__ );
foreach ( array( 'retention', 'dunning', 'pause', 'cancel-flow', 'reminders' ) as $fw_mbr_ret_part ) {
	require_once $fw_mbr_ret_dir . '/class-fw-membership-' . $fw_mbr_ret_part . '.php';
}
FW_Membership_Retention::init();
FW_Membership_Dunning::init();
FW_Membership_Pause::init();
FW_Membership_Cancel_Flow::init();
FW_Membership_Reminders::init();

add_filter( 'unysonplus_membership_nav', function ( $nav ) {
	$nav['insights']['items']['retention'] = array( 'label' => __( 'Retention', 'fw' ), 'icon' => 'dashicons-heart' );
	return $nav;
} );

add_action( 'unysonplus_membership_render_view', function ( $tab ) {
	if ( 'retention' !== $tab ) {
		return;
	}
	$days  = 90;
	$st    = FW_Membership_Cancel_Flow::stats( $days );
	$asked = array_sum( $st['reasons'] );
	$saved = array_sum( $st['saved'] );
	$cards = array(
		array( __( 'Asked why they were leaving', 'fw' ), $asked ),
		array( __( 'Kept by an offer', 'fw' ), $saved ),
		array( __( 'Paused instead', 'fw' ), $st['paused'] ),
		array( __( 'Cancelled', 'fw' ), $st['cancelled'] ),
		array( __( 'Failed payments', 'fw' ), $st['failed'] ),
		array( __( 'Recovered payments', 'fw' ), $st['recovered'] ),
	);
	?>
	<p class="fw-mbr-muted"><?php echo esc_html( sprintf( __( 'The last %d days.', 'fw' ), $days ) ); ?> <a href="<?php echo esc_url( FW_Membership_Admin_Page::get_page_url( 'settings' ) . '#fw-mbr-h-ret' ); ?>"><?php esc_html_e( 'Settings', 'fw' ); ?></a></p>
	<div class="fw-mbr-stats" role="list">
		<?php foreach ( $cards as $c ) : ?>
			<div class="fw-mbr-stat" role="listitem"><span class="fw-mbr-stat__label"><?php echo esc_html( $c[0] ); ?></span><span class="fw-mbr-stat__value"><?php echo esc_html( number_format_i18n( $c[1] ) ); ?></span></div>
		<?php endforeach; ?>
	</div>
	<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-reasons">
		<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-reasons"><?php esc_html_e( 'Why people leave', 'fw' ); ?></h2></header>
		<?php if ( ! $st['reasons'] ) : ?>
			<p class="fw-mbr-muted"><?php esc_html_e( 'Nobody has gone through the cancellation steps yet.', 'fw' ); ?></p>
		<?php else : ?>
			<table class="widefat striped"><tbody>
				<?php foreach ( $st['reasons'] as $reason => $n ) : ?>
					<tr><td><?php echo esc_html( '' !== $reason ? $reason : __( '(no answer)', 'fw' ) ); ?></td><td style="width:8em"><?php echo esc_html( sprintf( '%d (%d%%)', $n, round( 100 * $n / max( 1, $asked ) ) ) ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
	</section>
	<?php if ( $st['saved'] ) : ?>
		<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-saved">
			<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-saved"><?php esc_html_e( 'What kept them', 'fw' ); ?></h2></header>
			<table class="widefat striped"><tbody>
				<?php $labels = array( 'coupon' => __( 'A discount', 'fw' ), 'pause' => __( 'A pause', 'fw' ), 'downgrade' => __( 'A cheaper plan', 'fw' ) ); ?>
				<?php foreach ( $st['saved'] as $k => $n ) : ?>
					<tr><td><?php echo esc_html( $labels[ $k ] ?? $k ); ?></td><td style="width:8em"><?php echo (int) $n; ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		</section>
	<?php endif; ?>
	<?php
} );
