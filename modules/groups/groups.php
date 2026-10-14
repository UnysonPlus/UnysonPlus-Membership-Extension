<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Groups and teams module (Membership → Modules → Groups and teams). A plan with
 * "Team seats" makes its holder a team owner. Membership → Groups lists every team.
 */

require_once dirname( __FILE__ ) . '/class-fw-membership-groups.php';
FW_Membership_Groups::init();

add_filter( 'unysonplus_membership_nav', function ( $nav ) {
	$nav['main']['items']['groups'] = array( 'label' => __( 'Groups', 'fw' ), 'icon' => 'dashicons-groups' );
	return $nav;
} );

add_filter( 'unysonplus_membership_admin_action', function ( $back, $action ) {
	if ( 'save_group' !== $action ) {
		return $back;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- verified by the admin page before dispatch.
	$g = FW_Membership_Groups::get( (int) ( $_REQUEST['id'] ?? 0 ) );
	if ( $g ) {
		FW_Membership_Groups::update( $g['id'], array(
			'name'  => sanitize_text_field( wp_unslash( $_REQUEST['name'] ?? $g['name'] ) ),
			'seats' => max( 0, (int) ( $_REQUEST['seats'] ?? $g['seats'] ) ),
		) );
		FW_Membership_Admin_Page::flash( 'success', __( 'Team saved.', 'fw' ) );
	}
	// phpcs:enable
	return array( 'tab' => 'groups' );
}, 10, 2 );

add_action( 'unysonplus_membership_render_view', function ( $tab ) {
	if ( 'groups' !== $tab ) {
		return;
	}
	$groups = FW_Membership_Groups::all();
	if ( ! $groups ) {
		FW_Membership_App::empty_state( 'dashicons-groups', __( 'No teams yet', 'fw' ), __( 'Give a plan "Team seats" (Plans → a plan → Options). Whoever holds it becomes a team owner and invites people from their account page.', 'fw' ) );
		return;
	}
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead><tr><th scope="col" class="column-primary"><?php esc_html_e( 'Team', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Owner', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Plan', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Seats', 'fw' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $groups as $g ) : ?>
			<?php $owner = get_userdata( $g['owner_id'] ); $plan = FW_Membership_Plans::get( $g['plan_id'] ); $rows = FW_Membership_Groups::members( $g['id'] ); ?>
			<tr>
				<td class="column-primary">
					<form method="post" class="fw-mbr-inline">
						<?php FW_Membership_Admin_Page::form_fields( 'save_group' ); ?>
						<input type="hidden" name="id" value="<?php echo (int) $g['id']; ?>" />
						<input type="text" name="name" value="<?php echo esc_attr( $g['name'] ); ?>" aria-label="<?php esc_attr_e( 'Team name', 'fw' ); ?>" />
						<input type="number" min="0" name="seats" value="<?php echo (int) $g['seats']; ?>" style="width:5em" aria-label="<?php esc_attr_e( 'Seats', 'fw' ); ?>" />
						<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'fw' ); ?></button>
					</form>
					<?php if ( 'active' !== $g['status'] ) : ?><span class="fw-mbr-mstatus fw-mbr-mstatus--expired"><?php esc_html_e( 'Ended', 'fw' ); ?></span><?php endif; ?>
					<?php if ( $rows ) : ?>
						<details><summary><?php esc_html_e( 'Members', 'fw' ); ?></summary><ul>
							<?php foreach ( $rows as $row ) : ?>
								<?php $u = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null; ?>
								<li><?php echo esc_html( ( $u ? $u->display_name . ' · ' : '' ) . $row['email'] . ( 'invited' === $row['status'] ? ' (' . __( 'invited', 'fw' ) . ')' : '' ) ); ?></li>
							<?php endforeach; ?>
						</ul></details>
					<?php endif; ?>
				</td>
				<td><?php echo $owner ? '<a href="' . esc_url( FW_Membership_Admin_Page::get_page_url( 'members', array( 'member' => $owner->ID ) ) ) . '">' . esc_html( $owner->display_name ) . '</a>' : '—'; ?></td>
				<td><?php echo esc_html( $plan ? $plan['name'] : '—' ); ?></td>
				<td><?php echo esc_html( sprintf( '%d / %d', count( $rows ), $g['seats'] ) ); ?><?php echo '' !== $g['domain'] ? '<br /><span class="fw-mbr-muted">@' . esc_html( $g['domain'] ) . '</span>' : ''; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
} );
