<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Members: the list, a member's page and "Add a member", and their actions.
 * Every change goes through FW_Membership_Service, so history and events stay whole.
 */
class FW_Membership_Admin_Members {

	/**
	 * @param string $action
	 *
	 * @return array Query args to redirect to.
	 */
	public static function handle( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification -- verified by the admin page before dispatch.
		$r    = wp_unslash( $_REQUEST );
		$id   = (int) ( $r['id'] ?? 0 );
		$m    = $id ? FW_Membership_Memberships::get( $id ) : null;
		$back = $m ? array( 'tab' => 'members', 'member' => $m['user_id'] ) : array( 'tab' => 'members' );

		switch ( $action ) {
			case 'add_member':
				return self::handle_add( $r );

			case 'grant':
				$user = (int) ( $r['user_id'] ?? 0 );
				$res  = FW_Membership_Service::grant( $user, (int) ( $r['plan_id'] ?? 0 ), array(
					'source' => 'manual',
					'ends'   => self::ends_from_request( $r ),
					'note'   => sanitize_text_field( (string) ( $r['note'] ?? '' ) ),
				) );
				FW_Membership_Admin_Page::flash_result( $res, __( 'Plan added.', 'fw' ) );
				return array( 'tab' => 'members', 'member' => $user );

			case 'extend':
				$until = self::ends_from_request( $r );
				FW_Membership_Admin_Page::flash_result( FW_Membership_Service::extend( $id, $until ), __( 'Membership extended.', 'fw' ) );
				return $back;

			case 'cancel':
				FW_Membership_Admin_Page::flash_result( FW_Membership_Service::cancel( $id ), __( 'Membership cancelled. Access continues until its end date.', 'fw' ) );
				return $back;

			case 'expire':
				FW_Membership_Admin_Page::flash_result( FW_Membership_Service::expire( $id, __( 'Ended by an administrator.', 'fw' ) ), __( 'Membership ended. Access has stopped.', 'fw' ) );
				return $back;

			case 'reactivate':
				FW_Membership_Admin_Page::flash_result( FW_Membership_Service::reactivate( $id ), __( 'Membership is active again.', 'fw' ) );
				return $back;

			case 'change_plan':
				FW_Membership_Admin_Page::flash_result( FW_Membership_Service::change_plan( $id, (int) ( $r['plan_id'] ?? 0 ) ), __( 'Plan changed.', 'fw' ) );
				return $back;
		}
		// phpcs:enable

		return $back;
	}

	/**
	 * "ends" from a form: plan default, a date, or never.
	 *
	 * @param array $r
	 *
	 * @return string
	 */
	private static function ends_from_request( array $r ) {
		$mode = sanitize_key( (string) ( $r['ends_mode'] ?? 'plan' ) );
		if ( 'never' === $mode ) {
			return 'never';
		}
		if ( 'date' === $mode && ! empty( $r['ends_date'] ) ) {
			return sanitize_text_field( (string) $r['ends_date'] );
		}
		return '';
	}

	private static function handle_add( array $r ) {
		$email = sanitize_email( (string) ( $r['email'] ?? '' ) );
		$plan  = (int) ( $r['plan_id'] ?? 0 );
		$fail  = array( 'tab' => 'members', 'new' => 1 );

		if ( ! is_email( $email ) ) {
			FW_Membership_Admin_Page::flash( 'error', __( 'Enter a valid email address.', 'fw' ) );
			return $fail;
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			if ( empty( $r['create_account'] ) ) {
				FW_Membership_Admin_Page::flash( 'error', __( 'Nobody with that email has an account. Tick "Create an account" to make one.', 'fw' ) );
				return $fail;
			}
			if ( ! current_user_can( 'create_users' ) ) {
				FW_Membership_Admin_Page::flash( 'error', __( 'You are not allowed to create user accounts.', 'fw' ) );
				return $fail;
			}
			$login = sanitize_user( strstr( $email, '@', true ), true );
			$login = '' !== $login ? $login : 'member';
			$base  = $login;
			$n     = 1;
			while ( username_exists( $login ) ) {
				$login = $base . ( ++$n );
			}
			$first = sanitize_text_field( (string) ( $r['first_name'] ?? '' ) );
			$last  = sanitize_text_field( (string) ( $r['last_name'] ?? '' ) );
			// The member role is the site's default role; plans grant their extras on the fly.
			$user_id = wp_insert_user( array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ) ? trim( $first . ' ' . $last ) : $login,
				'role'         => get_option( 'default_role', 'subscriber' ),
			) );
			if ( is_wp_error( $user_id ) ) {
				FW_Membership_Admin_Page::flash( 'error', $user_id->get_error_message() );
				return $fail;
			}
			if ( ! empty( $r['notify'] ) ) {
				wp_new_user_notification( $user_id, null, 'user' );
			}
			$user = get_userdata( $user_id );
		}

		$res = FW_Membership_Service::grant( $user->ID, $plan, array(
			'source' => 'manual',
			'ends'   => self::ends_from_request( $r ),
			'note'   => sanitize_text_field( (string) ( $r['note'] ?? '' ) ),
		) );
		if ( ! FW_Membership_Admin_Page::flash_result( $res, __( 'Member added.', 'fw' ) ) ) {
			return $fail;
		}

		return array( 'tab' => 'members', 'member' => $user->ID );
	}

	/* ---------------------------------------------------------------------- *
	 * List
	 * ---------------------------------------------------------------------- */

	public static function render_list() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		$plan_id = isset( $_GET['plan_id'] ) ? (int) $_GET['plan_id'] : 0;
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$q       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$paged   = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		// phpcs:enable
		$per   = 25;
		$base  = FW_Membership_Admin_Page::get_page_url( 'members' );
		$plans = FW_Membership_Plans::all();

		if ( ! $plans ) {
			FW_Membership_App::empty_state(
				'dashicons-groups',
				__( 'Start with a plan', 'fw' ),
				__( 'Members hold plans, so create a plan first: Free, Supporter, Gold. Then add people to it here, or let them join through a form.', 'fw' ),
				array( array( __( 'New plan', 'fw' ), FW_Membership_Admin_Page::get_page_url( 'plans', array( 'new' => 1 ) ), true ) )
			);
			return;
		}

		$args = array(
			'plan_id'  => $plan_id,
			'status'   => '' === $status ? '' : ( 'access' === $status ? 'access' : array( $status ) ),
			'search'   => $q,
			'per_page' => $per,
			'page'     => $paged,
		);
		$rows   = FW_Membership_Memberships::query( $args );
		$total  = FW_Membership_Memberships::count( $args );
		$counts = FW_Membership_Memberships::counts_by_status();
		$labels = FW_Membership_App::status_labels();
		$filter = function ( $s ) use ( $base, $plan_id, $q ) {
			return add_query_arg( array_filter( array( 'status' => $s, 'plan_id' => $plan_id, 'q' => $q ) ), $base );
		};
		?>
		<ul class="subsubsub fw-mbr-views">
			<li><a href="<?php echo esc_url( $filter( '' ) ); ?>"<?php echo '' === $status ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'All', 'fw' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( array_sum( $counts ) ) ); ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( $filter( 'access' ) ); ?>"<?php echo 'access' === $status ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'With access', 'fw' ); ?></a><?php echo ' |'; ?></li>
			<?php
			$shown = array_filter( $counts );
			$i     = 0;
			foreach ( $shown as $s => $n ) :
				$i++;
				?>
				<li><a href="<?php echo esc_url( $filter( $s ) ); ?>"<?php echo $status === $s ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $labels[ $s ] ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $n ) ); ?>)</span></a><?php echo $i < count( $shown ) ? ' |' : ''; ?></li>
			<?php endforeach; ?>
		</ul>

		<div class="fw-mbr-listbar">
			<form method="get" class="fw-mbr-inline" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( FW_Membership_Admin_Page::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="members" />
				<?php if ( '' !== $status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" /><?php endif; ?>
				<label class="screen-reader-text" for="fw-mbr-filter-plan"><?php esc_html_e( 'Plan', 'fw' ); ?></label>
				<select id="fw-mbr-filter-plan" name="plan_id">
					<option value="0"><?php esc_html_e( 'All plans', 'fw' ); ?></option>
					<?php foreach ( $plans as $p ) : ?>
						<option value="<?php echo (int) $p['id']; ?>" <?php selected( $plan_id, $p['id'] ); ?>><?php echo esc_html( $p['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="fw-mbr-filter-q"><?php esc_html_e( 'Search', 'fw' ); ?></label>
				<input type="search" id="fw-mbr-filter-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Name or email', 'fw' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'fw' ); ?></button>
			</form>
			<span class="fw-mbr-listbar__end">
				<a class="button" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'export_members', array_filter( array( 'status' => $status, 'plan_id' => $plan_id, 'q' => $q ) ), 'members' ) ); ?>" title="<?php esc_attr_e( 'Download the memberships shown (with the current filters) as a CSV file the importer can read back', 'fw' ); ?>"><?php esc_html_e( 'Export CSV', 'fw' ); ?></a>
				<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'new', 1, $base ) ); ?>"><?php esc_html_e( 'Add a member', 'fw' ); ?></a>
			</span>
		</div>

		<?php if ( ! $rows ) : ?>
			<?php
			FW_Membership_App::empty_state(
				'dashicons-groups',
				array_sum( $counts ) ? __( 'Nobody matches', 'fw' ) : __( 'No members yet', 'fw' ),
				array_sum( $counts ) ? __( 'Try another plan, status or search.', 'fw' ) : __( 'Add someone by hand to try your plans out. People also join through a sign-up form, or the checkout when Payments is on.', 'fw' ),
				array_sum( $counts ) ? array( array( __( 'Show everyone', 'fw' ), $base ) ) : array( array( __( 'Add a member', 'fw' ), add_query_arg( 'new', 1, $base ), true ) )
			);
			?>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped fw-mbr-members-table">
				<thead>
					<tr>
						<th scope="col" class="column-primary"><?php esc_html_e( 'Member', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Plan', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Since', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Ends', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Source', 'fw' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $m ) : ?>
						<?php
						$u    = get_userdata( $m['user_id'] );
						$name = $u ? ( $u->display_name ? $u->display_name : $u->user_login ) : __( '(deleted user)', 'fw' );
						$plan = FW_Membership_Plans::get( $m['plan_id'] );
						$link = add_query_arg( 'member', $m['user_id'], $base );
						?>
						<tr>
							<td class="column-primary" data-colname="<?php esc_attr_e( 'Member', 'fw' ); ?>">
								<a class="fw-mbr-who" href="<?php echo esc_url( $link ); ?>">
									<?php echo FW_Membership_App::avatar( $name, 32 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<span class="fw-mbr-who__text">
										<span class="fw-mbr-who__name row-title"><?php echo esc_html( $name ); ?></span>
										<span class="fw-mbr-who__sub"><?php echo $u ? esc_html( $u->user_email ) : ''; ?></span>
									</span>
								</a>
							</td>
							<td data-colname="<?php esc_attr_e( 'Plan', 'fw' ); ?>"><?php echo $plan ? esc_html( $plan['name'] ) : esc_html__( '(deleted plan)', 'fw' ); ?></td>
							<td data-colname="<?php esc_attr_e( 'Status', 'fw' ); ?>"><?php echo FW_Membership_App::status_pill( $m['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td data-colname="<?php esc_attr_e( 'Since', 'fw' ); ?>"><?php echo FW_Membership_App::date( $m['started_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td data-colname="<?php esc_attr_e( 'Ends', 'fw' ); ?>"><?php echo FW_Membership_App::date( $m['ends_at'], __( 'Never', 'fw' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td data-colname="<?php esc_attr_e( 'Source', 'fw' ); ?>"><?php echo esc_html( self::source_label( $m['source'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / $per );
			if ( $pages > 1 ) :
				?>
				<div class="tablenav bottom"><div class="tablenav-pages">
					<?php
					echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					) );
					?>
				</div></div>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * A member's page
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $user_id
	 */
	public static function render_member( $user_id ) {
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			FW_Membership_App::empty_state( 'dashicons-warning', __( 'Member not found', 'fw' ), __( 'Their WordPress account may have been deleted.', 'fw' ), array( array( __( 'All members', 'fw' ), FW_Membership_Admin_Page::get_page_url( 'members' ) ) ) );
			return;
		}
		$name        = $u->display_name ? $u->display_name : $u->user_login;
		$memberships = FW_Membership_Memberships::query( array( 'user_id' => $user_id, 'per_page' => 100, 'orderby' => 'status' ) );
		$plans       = FW_Membership_Plans::all( 'active' );
		$history     = FW_Membership_Memberships::history( array( 'user_id' => $user_id, 'limit' => 30 ) );
		$crm_url     = '';
		if ( FW_Membership_App::can_open_crm() && class_exists( 'FW_CRM_Contacts' ) && method_exists( 'FW_CRM_Contacts', 'id_for_user' ) ) {
			$cid = (int) FW_CRM_Contacts::id_for_user( $user_id );
			if ( $cid && FW_Membership_App::can_open_crm( $cid ) ) {
				$crm_url = add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => $cid ), FW_CRM_Admin_Page::get_page_url() );
			}
		}
		?>
		<div class="fw-mbr-record">
			<div class="fw-mbr-record__main">
				<section class="fw-mbr-panel fw-mbr-person">
					<?php echo FW_Membership_App::avatar( $name, 56 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<div class="fw-mbr-person__text">
						<h2><?php echo esc_html( $name ); ?></h2>
						<p class="fw-mbr-muted">
							<a href="mailto:<?php echo esc_attr( $u->user_email ); ?>"><?php echo esc_html( $u->user_email ); ?></a>
							· <?php echo esc_html( sprintf( __( 'Account since %s', 'fw' ), date_i18n( get_option( 'date_format' ), strtotime( $u->user_registered ) ) ) ); ?>
						</p>
					</div>
					<p class="fw-mbr-person__links">
						<?php if ( current_user_can( 'edit_user', $user_id ) ) : ?>
							<a class="button" href="<?php echo esc_url( get_edit_user_link( $user_id ) ); ?>"><?php esc_html_e( 'WordPress profile', 'fw' ); ?></a>
						<?php endif; ?>
						<?php if ( $crm_url ) : ?>
							<a class="button" href="<?php echo esc_url( $crm_url ); ?>"><?php esc_html_e( 'Open in the CRM', 'fw' ); ?></a>
						<?php endif; ?>
					</p>
				</section>

				<?php FW_Membership_Admin_Settings::render_member_state( $user_id ); ?>

				<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-ms">
					<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-ms"><?php esc_html_e( 'Memberships', 'fw' ); ?></h2></header>
					<?php if ( ! $memberships ) : ?>
						<p class="fw-mbr-muted"><?php esc_html_e( 'No plans yet. Add one on the right.', 'fw' ); ?></p>
					<?php else : ?>
						<ul class="fw-mbr-mlist">
							<?php foreach ( $memberships as $m ) : ?>
								<?php self::render_membership_card( $m ); ?>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>

				<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-hist">
					<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-hist"><?php esc_html_e( 'History', 'fw' ); ?></h2></header>
					<?php if ( ! $history ) : ?>
						<p class="fw-mbr-muted"><?php esc_html_e( 'Nothing yet.', 'fw' ); ?></p>
					<?php else : ?>
						<ol class="fw-mbr-history">
							<?php foreach ( $history as $h ) : ?>
								<?php
								$plan  = FW_Membership_Plans::get( (int) $h->plan_id );
								$actor = (int) $h->actor_id ? get_userdata( (int) $h->actor_id ) : null;
								?>
								<li>
									<span class="fw-mbr-history__what">
										<strong><?php echo esc_html( self::event_label( $h->type ) ); ?></strong>
										<?php echo $plan ? '· ' . esc_html( $plan['name'] ) : ''; ?>
										<?php if ( '' !== (string) $h->note ) : ?>
											<span class="fw-mbr-muted">— <?php echo esc_html( $h->note ); ?></span>
										<?php endif; ?>
									</span>
									<span class="fw-mbr-history__when fw-mbr-muted">
										<?php echo FW_Membership_App::time_ago( $h->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<?php echo $actor ? esc_html( sprintf( __( 'by %s', 'fw' ), $actor->display_name ) ) : esc_html__( 'automatically', 'fw' ); ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</section>
				<?php
				/** More panels on a member's page, main column (the Security module's log). */
				do_action( 'unysonplus_membership_member_panels', $user_id );
				?>
			</div>

			<div class="fw-mbr-record__side">
				<?php FW_Membership_Admin_Settings::render_member_profile( $user_id ); ?>
				<?php
				/** More panels on a member's page, side column (passkeys, sessions). */
				do_action( 'unysonplus_membership_member_side_panels', $user_id );
				?>

				<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-grant">
					<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-grant"><?php esc_html_e( 'Add a plan', 'fw' ); ?></h2></header>
					<?php if ( ! $plans ) : ?>
						<p class="fw-mbr-muted"><?php esc_html_e( 'Create a plan first.', 'fw' ); ?></p>
					<?php else : ?>
						<form method="post" class="fw-mbr-fields fw-mbr-fields--stack">
							<?php FW_Membership_Admin_Page::form_fields( 'grant' ); ?>
							<input type="hidden" name="user_id" value="<?php echo (int) $user_id; ?>" />
							<p class="fw-mbr-field">
								<label for="fw-mbr-grant-plan"><?php esc_html_e( 'Plan', 'fw' ); ?></label>
								<select id="fw-mbr-grant-plan" name="plan_id"><?php self::plan_options( $plans ); ?></select>
							</p>
							<?php self::ends_fields( 'grant' ); ?>
							<p class="fw-mbr-field">
								<label for="fw-mbr-grant-note"><?php esc_html_e( 'Note (optional)', 'fw' ); ?></label>
								<input type="text" id="fw-mbr-grant-note" name="note" />
							</p>
							<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add plan', 'fw' ); ?></button></p>
						</form>
					<?php endif; ?>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array $m
	 */
	private static function render_membership_card( array $m ) {
		$plan   = FW_Membership_Plans::get( $m['plan_id'] );
		$tab    = 'members';
		$args   = array( 'id' => $m['id'] );
		$live   = in_array( $m['status'], FW_Membership_Memberships::ACCESS_STATUSES, true );
		$others = array_filter( FW_Membership_Plans::all( 'active' ), function ( $p ) use ( $m ) {
			return $p['id'] !== $m['plan_id'];
		} );
		$fid    = 'fw-mbr-m-' . $m['id'];
		?>
		<li class="fw-mbr-mcard">
			<div class="fw-mbr-mcard__head">
				<strong class="fw-mbr-mcard__plan"><?php echo $plan ? esc_html( $plan['name'] ) : esc_html__( '(deleted plan)', 'fw' ); ?></strong>
				<?php echo FW_Membership_App::status_pill( $m['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<dl class="fw-mbr-mcard__dates">
				<div><dt><?php esc_html_e( 'Started', 'fw' ); ?></dt><dd><?php echo FW_Membership_App::date( $m['started_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd></div>
				<div><dt><?php echo 'cancelled' === $m['status'] ? esc_html__( 'Access until', 'fw' ) : esc_html__( 'Ends', 'fw' ); ?></dt><dd><?php echo FW_Membership_App::date( $m['ends_at'], __( 'Never', 'fw' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd></div>
				<div><dt><?php esc_html_e( 'Source', 'fw' ); ?></dt><dd><?php echo esc_html( self::source_label( $m['source'] ) ); ?></dd></div>
			</dl>
			<div class="fw-mbr-mcard__actions">
				<details class="fw-mbr-mcard__more">
					<summary class="button button-small"><?php esc_html_e( 'Extend', 'fw' ); ?></summary>
					<form method="post" class="fw-mbr-fields fw-mbr-fields--stack">
						<?php FW_Membership_Admin_Page::form_fields( 'extend' ); ?>
						<input type="hidden" name="id" value="<?php echo (int) $m['id']; ?>" />
						<?php self::ends_fields( $fid . '-x', true ); ?>
						<p><button type="submit" class="button button-primary button-small"><?php esc_html_e( 'Extend', 'fw' ); ?></button></p>
					</form>
				</details>
				<?php if ( $others ) : ?>
					<details class="fw-mbr-mcard__more">
						<summary class="button button-small"><?php esc_html_e( 'Change plan', 'fw' ); ?></summary>
						<form method="post" class="fw-mbr-inline">
							<?php FW_Membership_Admin_Page::form_fields( 'change_plan' ); ?>
							<input type="hidden" name="id" value="<?php echo (int) $m['id']; ?>" />
							<label class="screen-reader-text" for="<?php echo esc_attr( $fid ); ?>-plan"><?php esc_html_e( 'New plan', 'fw' ); ?></label>
							<select id="<?php echo esc_attr( $fid ); ?>-plan" name="plan_id"><?php self::plan_options( $others ); ?></select>
							<button type="submit" class="button button-small"><?php esc_html_e( 'Change', 'fw' ); ?></button>
						</form>
					</details>
				<?php endif; ?>
				<?php if ( 'active' === $m['status'] || 'trialing' === $m['status'] || 'past_due' === $m['status'] ) : ?>
					<?php if ( $m['ends_at'] ) : ?>
						<a class="button button-small" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'cancel', $args, $tab ) ); ?>"><?php esc_html_e( 'Cancel at end date', 'fw' ); ?></a>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $live ) : ?>
					<a class="button button-small fw-mbr-danger" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'expire', $args, $tab ) ); ?>"
					   onclick="return confirm('<?php echo esc_js( __( 'End this membership now? Access stops immediately.', 'fw' ) ); ?>');"><?php esc_html_e( 'End now', 'fw' ); ?></a>
				<?php endif; ?>
				<?php if ( in_array( $m['status'], array( 'cancelled', 'expired', 'refunded' ), true ) ) : ?>
					<a class="button button-small" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'reactivate', $args, $tab ) ); ?>"><?php esc_html_e( 'Reactivate', 'fw' ); ?></a>
				<?php endif; ?>
			</div>
		</li>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Add a member
	 * ---------------------------------------------------------------------- */

	public static function render_new() {
		$plans = FW_Membership_Plans::all( 'active' );
		if ( ! $plans ) {
			FW_Membership_App::empty_state(
				'dashicons-awards',
				__( 'Create a plan first', 'fw' ),
				__( 'A member is someone holding a plan, so there needs to be a plan to give them.', 'fw' ),
				array( array( __( 'New plan', 'fw' ), FW_Membership_Admin_Page::get_page_url( 'plans', array( 'new' => 1 ) ), true ) )
			);
			return;
		}
		?>
		<form method="post" class="fw-mbr-panel fw-mbr-narrow" action="<?php echo esc_url( FW_Membership_Admin_Page::get_page_url( 'members' ) ); ?>">
			<?php FW_Membership_Admin_Page::form_fields( 'add_member' ); ?>
			<div class="fw-mbr-fields">
				<p class="fw-mbr-field fw-mbr-field--wide">
					<label for="fw-mbr-email"><?php esc_html_e( 'Email', 'fw' ); ?></label>
					<input type="email" id="fw-mbr-email" name="email" class="regular-text" required autocomplete="off" aria-describedby="fw-mbr-email-d" />
					<span class="description" id="fw-mbr-email-d"><?php esc_html_e( 'If they already have an account on this site, the plan is added to it.', 'fw' ); ?></span>
				</p>
				<p class="fw-mbr-field">
					<label for="fw-mbr-first"><?php esc_html_e( 'First name', 'fw' ); ?></label>
					<input type="text" id="fw-mbr-first" name="first_name" />
				</p>
				<p class="fw-mbr-field">
					<label for="fw-mbr-last"><?php esc_html_e( 'Last name', 'fw' ); ?></label>
					<input type="text" id="fw-mbr-last" name="last_name" />
				</p>
				<p class="fw-mbr-field fw-mbr-field--wide">
					<label for="fw-mbr-plan"><?php esc_html_e( 'Plan', 'fw' ); ?></label>
					<select id="fw-mbr-plan" name="plan_id"><?php self::plan_options( $plans ); ?></select>
				</p>
			</div>
			<?php self::ends_fields( 'new' ); ?>
			<fieldset class="fw-mbr-choices">
				<legend class="screen-reader-text"><?php esc_html_e( 'Account', 'fw' ); ?></legend>
				<label><input type="checkbox" name="create_account" value="1" checked /> <?php esc_html_e( 'Create an account if they do not have one', 'fw' ); ?></label>
				<label><input type="checkbox" name="notify" value="1" checked /> <?php esc_html_e( 'Email them a link to set their password', 'fw' ); ?></label>
			</fieldset>
			<p class="fw-mbr-field">
				<label for="fw-mbr-note"><?php esc_html_e( 'Note (optional)', 'fw' ); ?></label>
				<input type="text" id="fw-mbr-note" name="note" class="regular-text" placeholder="<?php esc_attr_e( 'Paid by bank transfer', 'fw' ); ?>" />
			</p>
			<p class="fw-mbr-actions">
				<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Add member', 'fw' ); ?></button>
				<a class="button button-large" href="<?php echo esc_url( FW_Membership_Admin_Page::get_page_url( 'members' ) ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
			</p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Pieces
	 * ---------------------------------------------------------------------- */

	private static function plan_options( array $plans ) {
		foreach ( $plans as $p ) {
			printf( '<option value="%d">%s — %s</option>', (int) $p['id'], esc_html( $p['name'] ), esc_html( FW_Membership_Plans::duration_label( $p ) ) );
		}
	}

	/**
	 * "Ends: the plan's own length / on a date / never".
	 *
	 * @param string $id     Unique id prefix.
	 * @param bool   $extend Wording for the Extend form.
	 */
	private static function ends_fields( $id, $extend = false ) {
		$id = 'fw-mbr-ends-' . sanitize_html_class( $id );
		?>
		<fieldset class="fw-mbr-choices">
			<legend<?php echo $extend ? ' class="screen-reader-text"' : ''; ?>><?php echo $extend ? esc_html__( 'Extend by', 'fw' ) : esc_html__( 'Ends', 'fw' ); ?></legend>
			<label><input type="radio" name="ends_mode" value="plan" checked /> <?php echo $extend ? esc_html__( 'By the plan’s own length', 'fw' ) : esc_html__( 'As the plan says', 'fw' ); ?></label>
			<label><input type="radio" name="ends_mode" value="date" /> <?php echo $extend ? esc_html__( 'Until a date', 'fw' ) : esc_html__( 'On a date', 'fw' ); ?></label>
			<span class="fw-mbr-inline">
				<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-date"><?php esc_html_e( 'Date', 'fw' ); ?></label>
				<input type="date" id="<?php echo esc_attr( $id ); ?>-date" name="ends_date" />
			</span>
			<label><input type="radio" name="ends_mode" value="never" /> <?php esc_html_e( 'Never', 'fw' ); ?></label>
		</fieldset>
		<?php
	}

	/**
	 * @param string $source
	 *
	 * @return string
	 */
	public static function source_label( $source ) {
		$labels = array(
			'manual'         => __( 'Added by hand', 'fw' ),
			'payment'        => __( 'Payment', 'fw' ),
			'woocommerce'    => __( 'Store order', 'fw' ),
			'group_seat'     => __( 'Group seat', 'fw' ),
			'gift'           => __( 'Gift', 'fw' ),
			'import'         => __( 'Import', 'fw' ),
			'crm_automation' => __( 'CRM automation', 'fw' ),
			'registration'   => __( 'Sign-up', 'fw' ),
		);
		return $labels[ $source ] ?? $source;
	}

	/**
	 * @param string $type
	 *
	 * @return string
	 */
	public static function event_label( $type ) {
		$labels = array(
			'pending'           => __( 'Signed up, waiting to start', 'fw' ),
			'joined'            => __( 'Joined', 'fw' ),
			'trial_started'     => __( 'Trial started', 'fw' ),
			'renewed'           => __( 'Extended', 'fw' ),
			'payment_failed'    => __( 'Payment failed', 'fw' ),
			'payment_recovered' => __( 'Payment recovered', 'fw' ),
			'plan_changed'      => __( 'Plan changed', 'fw' ),
			'paused'            => __( 'Paused', 'fw' ),
			'resumed'           => __( 'Resumed', 'fw' ),
			'cancelled'         => __( 'Cancelled', 'fw' ),
			'reactivated'       => __( 'Reactivated', 'fw' ),
			'expired'           => __( 'Ended', 'fw' ),
			'refunded'          => __( 'Refunded', 'fw' ),
		);
		return $labels[ $type ] ?? $type;
	}
}
