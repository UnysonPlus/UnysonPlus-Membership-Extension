<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Gift memberships module (Membership → Modules → Gift memberships). Needs Payments.
 * "Give as a gift" on the Pricing Plans element (or a checkout link with &gift=1); the
 * recipient redeems on the checkout page (?redeem=CODE). Membership → Gifts lists them.
 */

require_once dirname( __FILE__ ) . '/class-fw-membership-gifts.php';
FW_Membership_Gifts::init();

add_filter( 'unysonplus_membership_nav', function ( $nav ) {
	$nav['selling']['items']['gifts'] = array( 'label' => __( 'Gifts', 'fw' ), 'icon' => 'dashicons-buddicons-community' );
	return $nav;
} );

add_filter( 'unysonplus_membership_admin_action', function ( $back, $action ) {
	// phpcs:disable WordPress.Security.NonceVerification -- verified by the admin page before dispatch.
	if ( ! in_array( $action, array( 'gift_resend', 'gift_cancel' ), true ) ) {
		return $back;
	}
	$g = FW_Membership_Gifts::get( (int) ( $_REQUEST['id'] ?? 0 ) );
	// phpcs:enable
	if ( $g && 'gift_resend' === $action && 'sent' === $g['status'] ) {
		FW_Membership_Gifts::send( $g );
		FW_Membership_Admin_Page::flash( 'success', __( 'The gift email was sent again.', 'fw' ) );
	} elseif ( $g && 'gift_cancel' === $action && 'redeemed' !== $g['status'] ) {
		global $wpdb;
		$wpdb->update( FW_Membership_Installer::table( 'gifts' ), array( 'status' => 'cancelled' ), array( 'id' => (int) $g['id'] ) );
		FW_Membership_Admin_Page::flash( 'success', __( 'Gift cancelled. Refund the payment on the Payments screen if needed.', 'fw' ) );
	}
	return array( 'tab' => 'gifts' );
}, 10, 2 );

add_action( 'unysonplus_membership_render_view', function ( $tab ) {
	if ( 'gifts' !== $tab ) {
		return;
	}
	$gifts  = FW_Membership_Gifts::query();
	$labels = array( 'pending' => __( 'Waiting for payment', 'fw' ), 'sent' => __( 'Sent', 'fw' ), 'redeemed' => __( 'Redeemed', 'fw' ), 'cancelled' => __( 'Cancelled', 'fw' ) );
	$pill   = array( 'pending' => 'pending', 'sent' => 'trialing', 'redeemed' => 'active', 'cancelled' => 'expired' );
	if ( ! $gifts ) {
		FW_Membership_App::empty_state( 'dashicons-buddicons-community', __( 'No gifts yet', 'fw' ), __( 'People give a plan with "Give as a gift" on your pricing table. The person it is for gets an email with a link to start it.', 'fw' ) );
		return;
	}
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead><tr><th scope="col" class="column-primary"><?php esc_html_e( 'Gift', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'From', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'For', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'fw' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $gifts as $g ) : ?>
			<?php $plan = FW_Membership_Plans::get( $g['plan_id'] ); $buyer = get_userdata( (int) $g['buyer_id'] ); ?>
			<tr>
				<td class="column-primary"><strong><?php echo esc_html( $plan ? $plan['name'] : '#' . $g['plan_id'] ); ?></strong><br /><code class="fw-mbr-muted"><?php echo esc_html( $g['code'] ); ?></code>
					<div class="row-actions">
						<?php if ( 'sent' === $g['status'] ) : ?><span><a href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'gift_resend', array( 'id' => $g['id'] ), 'gifts' ) ); ?>"><?php esc_html_e( 'Send again', 'fw' ); ?></a> | </span><?php endif; ?>
						<?php if ( 'redeemed' !== $g['status'] && 'cancelled' !== $g['status'] ) : ?><span class="trash"><a href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'gift_cancel', array( 'id' => $g['id'] ), 'gifts' ) ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a></span><?php endif; ?>
					</div>
				</td>
				<td><?php echo $buyer ? esc_html( $buyer->display_name ) : '—'; ?><br /><span class="fw-mbr-muted"><?php echo esc_html( FW_Membership_App::date( $g['created_at'] ) ); ?></span></td>
				<td><?php echo esc_html( trim( $g['recipient_name'] . ' ' ) ); ?><br /><span class="fw-mbr-muted"><?php echo esc_html( $g['recipient_email'] ); ?></span></td>
				<td><span class="fw-mbr-mstatus fw-mbr-mstatus--<?php echo esc_attr( $pill[ $g['status'] ] ?? 'expired' ); ?>"><?php echo esc_html( $labels[ $g['status'] ] ?? $g['status'] ); ?></span><?php echo $g['redeemed_at'] ? '<br /><span class="fw-mbr-muted">' . esc_html( FW_Membership_App::date( $g['redeemed_at'] ) ) . '</span>' : ''; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
} );
