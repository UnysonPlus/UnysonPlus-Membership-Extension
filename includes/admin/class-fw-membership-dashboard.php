<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Membership → Home: where things stand, or the first steps on a fresh install.
 */
class FW_Membership_Dashboard {

	public static function render() {
		$base   = FW_Membership_Admin_Page::get_page_url();
		$plans  = FW_Membership_Plans::all();
		$counts = FW_Membership_Memberships::counts_by_status();
		$total  = array_sum( $counts );

		if ( ! $plans || ! $total ) {
			self::render_welcome( $plans, $base );
			return;
		}

		$members  = FW_Membership_Memberships::count_members();
		$by_plan  = FW_Membership_Memberships::counts_by_plan();
		$month    = FW_Membership_Memberships::count( array( 'status' => 'access' ) );
		$ending   = self::ending_soon();
		?>
		<p class="fw-mbr-status-line">
			<?php
			echo esc_html( sprintf(
				/* translators: 1: "3 members with access", 2: "2 plans" */
				__( '%1$s across %2$s.', 'fw' ),
				sprintf( _n( '%s member with access', '%s members with access', $members, 'fw' ), number_format_i18n( $members ) ),
				sprintf( _n( '%s plan', '%s plans', count( $plans ), 'fw' ), number_format_i18n( count( $plans ) ) )
			) );
			if ( $ending ) {
				echo ' ' . esc_html( sprintf( _n( '%s membership ends in the next 14 days.', '%s memberships end in the next 14 days.', count( $ending ), 'fw' ), number_format_i18n( count( $ending ) ) ) );
			}
			?>
		</p>

		<div class="fw-mbr-stats" role="list">
			<?php
			$stats = array(
				array( __( 'Members', 'fw' ), $members, add_query_arg( array( 'tab' => 'members', 'status' => 'access' ), $base ), sprintf( __( '%s memberships', 'fw' ), number_format_i18n( $month ) ) ),
				array( __( 'Active', 'fw' ), $counts['active'] + $counts['trialing'], add_query_arg( array( 'tab' => 'members', 'status' => 'active' ), $base ), $counts['trialing'] ? sprintf( __( '%s on a trial', 'fw' ), number_format_i18n( $counts['trialing'] ) ) : '' ),
				array( __( 'Cancelled, still with access', 'fw' ), $counts['cancelled'], add_query_arg( array( 'tab' => 'members', 'status' => 'cancelled' ), $base ), '' ),
				array( __( 'Ended', 'fw' ), $counts['expired'] + $counts['refunded'], add_query_arg( array( 'tab' => 'members', 'status' => 'expired' ), $base ), '' ),
			);
			foreach ( $stats as $s ) :
				?>
				<a class="fw-mbr-stat" role="listitem" href="<?php echo esc_url( $s[2] ); ?>">
					<span class="fw-mbr-stat__label"><?php echo esc_html( $s[0] ); ?></span>
					<span class="fw-mbr-stat__value"><?php echo esc_html( number_format_i18n( $s[1] ) ); ?></span>
					<?php if ( '' !== $s[3] ) : ?>
						<span class="fw-mbr-stat__note"><?php echo esc_html( $s[3] ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>

		<div class="fw-mbr-home">
			<div class="fw-mbr-home__main">
				<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-plans">
					<header class="fw-mbr-panel__head">
						<h2 id="fw-mbr-h-plans"><?php esc_html_e( 'Members by plan', 'fw' ); ?></h2>
						<a href="<?php echo esc_url( add_query_arg( 'tab', 'plans', $base ) ); ?>"><?php esc_html_e( 'All plans', 'fw' ); ?></a>
					</header>
					<ul class="fw-mbr-bars">
						<?php
						$max = max( 1, max( $by_plan ? $by_plan : array( 0 ) ) );
						foreach ( $plans as $plan ) :
							$n = $by_plan[ $plan['id'] ] ?? 0;
							?>
							<li>
								<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'members', 'plan_id' => $plan['id'], 'status' => 'access' ), $base ) ); ?>">
									<span class="fw-mbr-bars__label"><?php echo esc_html( $plan['name'] ); ?></span>
									<span class="fw-mbr-bars__track" aria-hidden="true"><span class="fw-mbr-bars__fill" style="width:<?php echo (int) round( $n / $max * 100 ); ?>%"></span></span>
									<span class="fw-mbr-bars__value"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>

				<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-recent">
					<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-recent"><?php esc_html_e( 'Recent changes', 'fw' ); ?></h2></header>
					<?php self::render_recent( $base ); ?>
				</section>
			</div>

			<div class="fw-mbr-home__side">
				<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-ending">
					<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-ending"><?php esc_html_e( 'Ending in the next 14 days', 'fw' ); ?></h2></header>
					<?php if ( ! $ending ) : ?>
						<p class="fw-mbr-muted"><?php esc_html_e( 'No memberships end in the next two weeks.', 'fw' ); ?></p>
					<?php else : ?>
						<ul class="fw-mbr-people">
							<?php foreach ( array_slice( $ending, 0, 8 ) as $m ) : ?>
								<?php
								$u    = get_userdata( $m['user_id'] );
								$name = $u ? ( $u->display_name ? $u->display_name : $u->user_login ) : '?';
								$plan = FW_Membership_Plans::get( $m['plan_id'] );
								?>
								<li>
									<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'members', 'member' => $m['user_id'] ), $base ) ); ?>">
										<?php echo FW_Membership_App::avatar( $name, 32 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<span class="fw-mbr-people__text">
											<span class="fw-mbr-people__name"><?php echo esc_html( $name ); ?></span>
											<span class="fw-mbr-people__sub"><?php echo $plan ? esc_html( $plan['name'] ) : ''; ?></span>
										</span>
										<?php echo FW_Membership_App::time_ago( $m['ends_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>

				<?php FW_Membership_Health::render(); ?>
			</div>
		</div>
		<?php
	}

	private static function ending_soon() {
		$rows = FW_Membership_Memberships::query( array( 'status' => array( 'active', 'cancelled', 'trialing', 'past_due' ), 'orderby' => 'ends_at', 'order' => 'ASC', 'per_page' => 200 ) );
		$now  = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		return array_values( array_filter( $rows, function ( $m ) use ( $now ) {
			if ( ! $m['ends_at'] ) {
				return false;
			}
			$t = strtotime( $m['ends_at'] );
			return $t > $now && $t <= $now + 14 * DAY_IN_SECONDS;
		} ) );
	}

	private static function render_recent( $base ) {
		$events = FW_Membership_Memberships::history( array( 'limit' => 10 ) );
		if ( ! $events ) {
			echo '<p class="fw-mbr-muted">' . esc_html__( 'Nothing yet.', 'fw' ) . '</p>';
			return;
		}
		?>
		<ol class="fw-mbr-history">
			<?php foreach ( $events as $h ) : ?>
				<?php
				$u    = get_userdata( (int) $h->user_id );
				$name = $u ? ( $u->display_name ? $u->display_name : $u->user_login ) : __( '(deleted user)', 'fw' );
				$plan = FW_Membership_Plans::get( (int) $h->plan_id );
				?>
				<li>
					<span class="fw-mbr-history__what">
						<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'members', 'member' => (int) $h->user_id ), $base ) ); ?>"><?php echo esc_html( $name ); ?></a>
						· <strong><?php echo esc_html( FW_Membership_Admin_Members::event_label( $h->type ) ); ?></strong>
						<?php echo $plan ? '· ' . esc_html( $plan['name'] ) : ''; ?>
					</span>
					<span class="fw-mbr-history__when fw-mbr-muted"><?php echo FW_Membership_App::time_ago( $h->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * @param array  $plans
	 * @param string $base
	 */
	private static function render_welcome( array $plans, $base ) {
		$steps = array(
			array( 'dashicons-awards', __( 'Create a plan', 'fw' ), __( 'What people get when they join: Free, Supporter, Gold. Add prices for later and how long it lasts.', 'fw' ), __( 'New plan', 'fw' ), add_query_arg( array( 'tab' => 'plans', 'new' => 1 ), $base ), (bool) $plans ),
			array( 'dashicons-admin-users', __( 'Add your first member', 'fw' ), __( 'Give someone a plan by hand to see how a membership looks: its dates, its history, and the actions on it.', 'fw' ), __( 'Add a member', 'fw' ), add_query_arg( array( 'tab' => 'members', 'new' => 1 ), $base ), false ),
			array( 'dashicons-screenoptions', __( 'Choose your modules', 'fw' ), __( 'Payments, drip content, groups, courses and more are switched on when you need them. Each one lists the phase it arrives in.', 'fw' ), __( 'Open Modules', 'fw' ), add_query_arg( 'tab', 'modules', $base ), false ),
		);
		?>
		<div class="fw-mbr-welcome">
			<h2><?php esc_html_e( 'Set up your membership site', 'fw' ); ?></h2>
			<p class="fw-mbr-muted"><?php esc_html_e( 'Three steps get you started. Then protect content with Access rules, and switch on Payments in Modules to sell plans.', 'fw' ); ?></p>
			<ol class="fw-mbr-welcome__steps">
				<?php foreach ( $steps as $s ) : ?>
					<li class="fw-mbr-step<?php echo $s[5] ? ' is-done' : ''; ?>">
						<span class="dashicons <?php echo esc_attr( $s[5] ? 'dashicons-yes-alt' : $s[0] ); ?>" aria-hidden="true"></span>
						<h3><?php echo esc_html( $s[1] ); ?></h3>
						<p><?php echo esc_html( $s[2] ); ?></p>
						<a class="button<?php echo $s[5] ? '' : ' button-primary'; ?>" href="<?php echo esc_url( $s[4] ); ?>"><?php echo esc_html( $s[3] ); ?></a>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}
}
