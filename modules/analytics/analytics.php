<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Reports module (Membership → Modules → Reports): Membership → Reports.
 *
 *   class-fw-membership-reports.php   MRR, movements, churn, lifetime value, trials
 *   class-fw-membership-cohorts.php   retention by month and source; most-read content
 */

$fw_mbr_an_dir = dirname( __FILE__ );
require_once $fw_mbr_an_dir . '/class-fw-membership-reports.php';
require_once $fw_mbr_an_dir . '/class-fw-membership-cohorts.php';
FW_Membership_Cohorts::init();

add_filter( 'unysonplus_membership_nav', function ( $nav ) {
	if ( isset( $nav['insights']['items']['reports'] ) ) {
		unset( $nav['insights']['items']['reports']['soon'] );
	}
	return $nav;
} );

add_filter( 'unysonplus_membership_admin_action', function ( $back, $action ) {
	if ( 'reports_csv' !== $action ) {
		return $back;
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="membership-report-' . current_time( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'month', 'members_at_start', 'new', 'upgrades', 'downgrades', 'lost', 'churn_percent', 'failed_payments', 'recovered_payments', 'revenue', 'mrr_at_end' ) );
	foreach ( FW_Membership_Reports::monthly_table( 24 ) as $ym => $r ) {
		fputcsv( $out, array( $ym, $r['start_members'], $r['new'], $r['upgrades'], $r['downgrades'], $r['lost'], $r['churn'], $r['failed'], $r['recovered'], FW_Membership_Reports::money_list( $r['revenue'] ), FW_Membership_Reports::money_list( $r['mrr'] ) ) );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}, 10, 2 );

/**
 * A small bar chart (inline SVG, no library).
 *
 * @param array  $values label => number
 * @param string $title
 *
 * @return string
 */
function fw_mbr_bar_chart( array $values, $title ) {
	$max = max( 1, max( $values ? array_values( $values ) : array( 0 ) ) );
	$n   = max( 1, count( $values ) );
	$w   = 640;
	$h   = 160;
	$bw  = $w / $n;
	$svg = '<svg class="fw-mbr-chart" viewBox="0 0 ' . $w . ' ' . ( $h + 22 ) . '" role="img" aria-label="' . esc_attr( $title ) . '"><title>' . esc_html( $title ) . '</title>';
	$i   = 0;
	foreach ( $values as $label => $v ) {
		$bh   = round( ( $h - 14 ) * $v / $max );
		$x    = round( $i * $bw + $bw * 0.15 );
		$svg .= '<rect x="' . $x . '" y="' . ( $h - $bh ) . '" width="' . round( $bw * 0.7 ) . '" height="' . max( 0, $bh ) . '" rx="3"><title>' . esc_html( $label . ': ' . $v ) . '</title></rect>';
		$svg .= '<text x="' . round( $x + $bw * 0.35 ) . '" y="' . ( $h + 16 ) . '" text-anchor="middle">' . esc_html( substr( (string) $label, 5 ) ) . '</text>';
		if ( $v > 0 ) {
			$svg .= '<text class="v" x="' . round( $x + $bw * 0.35 ) . '" y="' . max( 12, $h - $bh - 4 ) . '" text-anchor="middle">' . esc_html( number_format_i18n( $v, $v < 10 && floor( $v ) != $v ? 1 : 0 ) ) . '</text>';
		}
		$i++;
	}
	return $svg . '</svg>';
}

add_action( 'unysonplus_membership_render_view', function ( $tab ) {
	if ( 'reports' !== $tab ) {
		return;
	}
	$mrr     = FW_Membership_Reports::mrr();
	$table   = FW_Membership_Reports::monthly_table( 12 );
	$ltv     = FW_Membership_Reports::ltv();
	$trials  = FW_Membership_Reports::trials();
	$last    = end( $table );
	$cur     = class_exists( 'FW_Membership_Gateways' ) ? FW_Membership_Gateways::currency() : (string) array_key_first( $mrr['total'] );
	$failed  = array_sum( wp_list_pluck( $table, 'failed' ) );
	$recov   = array_sum( wp_list_pluck( $table, 'recovered' ) );
	$avg_ltv = array();
	foreach ( $ltv as $c => $row ) {
		$avg_ltv[ $c ] = $row['average'];
	}
	$cards = array(
		array( __( 'Monthly recurring revenue', 'fw' ), FW_Membership_Reports::money_list( $mrr['total'] ) ),
		array( __( 'Paying members', 'fw' ), number_format_i18n( $mrr['members'] ) ),
		array( __( 'Churn last month', 'fw' ), $last ? $last['churn'] . '%' : '—' ),
		array( __( 'Lifetime value (average)', 'fw' ), FW_Membership_Reports::money_list( $avg_ltv ) ),
		array( __( 'Trial conversion', 'fw' ), $trials['started'] ? $trials['rate'] . '%' : '—' ),
		array( __( 'Failed payments recovered', 'fw' ), $failed ? round( 100 * $recov / $failed ) . '%' : '—' ),
	);
	$mrr_series = array();
	foreach ( $table as $ym => $r ) {
		$mrr_series[ $ym ] = round( (float) ( $r['mrr'][ $cur ] ?? 0 ), 2 );
	}
	?>
	<div class="fw-mbr-listbar">
		<p class="fw-mbr-status-line"><?php esc_html_e( 'Worked out from the memberships, their history and the payments. Amounts stay in their own currency.', 'fw' ); ?></p>
		<a class="button" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'reports_csv', array(), 'reports' ) ); ?>"><?php esc_html_e( 'Download as CSV', 'fw' ); ?></a>
	</div>
	<div class="fw-mbr-stats" role="list">
		<?php foreach ( $cards as $c ) : ?>
			<div class="fw-mbr-stat" role="listitem"><span class="fw-mbr-stat__label"><?php echo esc_html( $c[0] ); ?></span><span class="fw-mbr-stat__value"><?php echo esc_html( $c[1] ); ?></span></div>
		<?php endforeach; ?>
	</div>

	<section class="fw-mbr-panel">
		<header class="fw-mbr-panel__head"><h2><?php echo esc_html( sprintf( __( 'Monthly recurring revenue (%s), end of each month', 'fw' ), $cur ) ); ?></h2></header>
		<?php if ( array_sum( $mrr_series ) > 0 ) : ?>
			<?php echo fw_mbr_bar_chart( $mrr_series, __( 'Monthly recurring revenue', 'fw' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php else : ?>
			<p class="fw-mbr-muted"><?php esc_html_e( 'No repeating payments yet. The chart fills in as members pay monthly or yearly.', 'fw' ); ?></p>
		<?php endif; ?>
	</section>

	<section class="fw-mbr-panel">
		<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'Month by month', 'fw' ); ?></h2></header>
		<table class="widefat striped fw-mbr-report-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Month', 'fw' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Members at start', 'fw' ); ?></th>
				<th scope="col"><?php esc_html_e( 'New', 'fw' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Upgrades', 'fw' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Downgrades', 'fw' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Lost', 'fw' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Churn', 'fw' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Failed / recovered', 'fw' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Revenue', 'fw' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( array_reverse( $table, true ) as $ym => $r ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( date_i18n( 'F Y', strtotime( $ym . '-01' ) ) ); ?></th>
					<td><?php echo (int) $r['start_members']; ?></td>
					<td><?php echo (int) $r['new']; ?></td>
					<td><?php echo (int) $r['upgrades']; ?></td>
					<td><?php echo (int) $r['downgrades']; ?></td>
					<td><?php echo (int) $r['lost']; ?></td>
					<td><?php echo esc_html( $r['churn'] . '%' ); ?></td>
					<td><?php echo (int) $r['failed'] . ' / ' . (int) $r['recovered']; ?></td>
					<td><?php echo esc_html( FW_Membership_Reports::money_list( $r['revenue'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<section class="fw-mbr-panel">
		<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'By plan', 'fw' ); ?></h2></header>
		<table class="widefat striped">
			<thead><tr><th scope="col"><?php esc_html_e( 'Plan', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Members with access', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Monthly recurring revenue', 'fw' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( FW_Membership_Memberships::counts_by_plan() as $pid => $count ) : ?>
				<?php $plan = FW_Membership_Plans::get( $pid ); ?>
				<tr><td><?php echo esc_html( $plan ? $plan['name'] : '#' . $pid ); ?></td><td><?php echo (int) $count; ?></td><td><?php echo esc_html( FW_Membership_Reports::money_list( $mrr['plans'][ $pid ] ?? array() ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<?php $cohorts = FW_Membership_Cohorts::by_month( 12 ); ?>
	<section class="fw-mbr-panel">
		<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'Still members, by the month they joined', 'fw' ); ?></h2></header>
		<div class="fw-mbr-table-scroll">
			<table class="widefat fw-mbr-cohorts">
				<thead><tr><th scope="col"><?php esc_html_e( 'Joined', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'People', 'fw' ); ?></th>
					<?php for ( $k = 1; $k <= 11; $k++ ) : ?><th scope="col"><?php echo esc_html( sprintf( __( '+%d mo', 'fw' ), $k ) ); ?></th><?php endfor; ?>
				</tr></thead>
				<tbody>
				<?php foreach ( array_reverse( $cohorts, true ) as $ym => $c ) : ?>
					<tr><th scope="row"><?php echo esc_html( date_i18n( 'M Y', strtotime( $ym . '-01' ) ) ); ?></th><td><?php echo (int) $c['size']; ?></td>
						<?php for ( $k = 1; $k <= 11; $k++ ) : ?>
							<?php $v = $c['retained'][ $k ] ?? null; ?>
							<td<?php echo null !== $v ? ' style="background:color-mix(in srgb, var(--mbr-accent) ' . (int) round( $v * 0.6 ) . '%, transparent)"' : ''; ?>><?php echo null !== $v ? (int) $v . '%' : ''; ?></td>
						<?php endfor; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

	<div class="fw-mbr-report-two">
		<section class="fw-mbr-panel">
			<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'Still members, by how they came', 'fw' ); ?></h2></header>
			<?php $labels = array( 'payment' => __( 'Paid sign-up', 'fw' ), 'registration' => __( 'Free sign-up', 'fw' ), 'manual' => __( 'Added by hand', 'fw' ), 'gift' => __( 'Gift', 'fw' ), 'group_seat' => __( 'Team seat', 'fw' ), 'woocommerce' => __( 'Store order', 'fw' ), 'crm_automation' => __( 'CRM automation', 'fw' ), 'import' => __( 'Import', 'fw' ) ); ?>
			<table class="widefat striped"><tbody>
				<?php foreach ( FW_Membership_Cohorts::by_source() as $src => $row ) : ?>
					<tr><td><?php echo esc_html( $labels[ $src ] ?? $src ); ?></td><td><?php echo esc_html( sprintf( '%1$d%% (%2$d of %3$d)', $row['rate'], $row['with_access'], $row['total'] ) ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		</section>
		<section class="fw-mbr-panel">
			<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'Most-read members-only content (3 months)', 'fw' ); ?></h2></header>
			<?php $top = FW_Membership_Cohorts::top_content(); ?>
			<?php if ( ! $top ) : ?>
				<p class="fw-mbr-muted"><?php esc_html_e( 'Counted from now on, each time a member opens a protected page or post (no record of who).', 'fw' ); ?></p>
			<?php else : ?>
				<table class="widefat striped"><tbody>
					<?php foreach ( $top as $row ) : ?>
						<tr><td><a href="<?php echo esc_url( get_permalink( $row['post_id'] ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a></td><td><?php echo (int) $row['views']; ?></td></tr>
					<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</section>
	</div>
	<?php
} );
