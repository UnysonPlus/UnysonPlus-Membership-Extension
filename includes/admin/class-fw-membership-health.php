<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Scheduler status: whether the jobs Membership needs (ending memberships every hour;
 * drip and retention once a day while those modules are on) run on time, on Membership →
 * Home and in WordPress's Site Health. WordPress runs scheduled jobs when someone visits
 * the site, so on a quiet site, or with WP-Cron switched off, they can run late; the card
 * then gives the exact line to add to the server's scheduler.
 *
 * Last runs are recorded by FW_Membership_Service (option fw_ext_membership_cron_last).
 */
class FW_Membership_Health {

	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, '_site_health' ) );
	}

	/** @return array[] hook => { label, every (seconds), next, last, state ok|late|never|off } */
	public static function jobs() {
		$last = (array) get_option( 'fw_ext_membership_cron_last', array() );
		$list = array( FW_Membership_Service::CRON_HOOK => array( __( 'End memberships that reach their end date', 'fw' ), HOUR_IN_SECONDS ) );
		if ( FW_Membership_Modules::is_on( 'drip' ) ) {
			$list['fw_mbr_drip_daily'] = array( __( 'Drip: email what opened today', 'fw' ), DAY_IN_SECONDS );
		}
		if ( FW_Membership_Modules::is_on( 'retention' ) ) {
			$list['fw_mbr_retention_daily'] = array( __( 'Retention: reminders, payment follow-ups, pauses ending', 'fw' ), DAY_IN_SECONDS );
		}
		$now = time();
		$out = array();
		foreach ( $list as $hook => $j ) {
			$next  = (int) wp_next_scheduled( $hook );
			$ran   = (int) ( $last[ $hook ] ?? 0 );
			$state = 'ok';
			if ( ! $next ) {
				$state = 'off';
			} elseif ( $next < $now - max( HOUR_IN_SECONDS, $j[1] / 4 ) ) {
				$state = 'late';
			} elseif ( ! $ran ) {
				$state = 'never';
			}
			$out[ $hook ] = array( 'label' => $j[0], 'every' => $j[1], 'next' => $next, 'last' => $ran, 'state' => $state );
		}
		return $out;
	}

	/** @return string The server scheduler line that runs WordPress's jobs every 5 minutes. */
	public static function cron_line() {
		return '*/5 * * * * wget -q -O - ' . esc_url_raw( site_url( 'wp-cron.php?doing_wp_cron' ) ) . ' >/dev/null 2>&1';
	}

	/** Render the card. */
	public static function render() {
		$jobs     = self::jobs();
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$bad      = array_filter( $jobs, function ( $j ) {
			return in_array( $j['state'], array( 'late', 'off' ), true );
		} );
		$labels   = array( 'ok' => __( 'On time', 'fw' ), 'late' => __( 'Late', 'fw' ), 'never' => __( 'Not run yet', 'fw' ), 'off' => __( 'Not scheduled', 'fw' ) );
		$pill     = array( 'ok' => 'active', 'late' => 'past_due', 'never' => 'pending', 'off' => 'expired' );
		?>
		<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-health">
			<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-health"><?php esc_html_e( 'Scheduled jobs', 'fw' ); ?></h2></header>
			<ul class="fw-mbr-health">
				<?php foreach ( $jobs as $j ) : ?>
					<li>
						<span><?php echo esc_html( $j['label'] ); ?></span>
						<span class="fw-mbr-mstatus fw-mbr-mstatus--<?php echo esc_attr( $pill[ $j['state'] ] ); ?>"><?php echo esc_html( $labels[ $j['state'] ] ); ?></span>
						<span class="fw-mbr-muted"><?php echo esc_html( $j['last'] ? sprintf( __( 'last run %s ago', 'fw' ), human_time_diff( $j['last'] ) ) : '' ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $bad || $disabled ) : ?>
				<p><?php echo esc_html( $disabled ? __( 'WordPress’s own scheduler is switched off on this site (DISABLE_WP_CRON), so add this line to the server’s scheduler (cron), or ask your host to:', 'fw' ) : __( 'Jobs are running late. WordPress runs them when someone visits, so on a quiet site add this line to the server’s scheduler (cron), or ask your host to:', 'fw' ) ); ?></p>
				<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( self::cron_line() ); ?>" onclick="this.select()" /></p>
				<p class="fw-mbr-muted"><?php esc_html_e( 'With WP-CLI on the server you can also run: wp cron event run --due-now', 'fw' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/** @internal A Site Health test. */
	public static function _site_health( $tests ) {
		$tests['direct']['fw_membership_jobs'] = array(
			'label' => __( 'Membership scheduled jobs', 'fw' ),
			'test'  => function () {
				$bad = array_filter( self::jobs(), function ( $j ) {
					return in_array( $j['state'], array( 'late', 'off' ), true );
				} );
				return array(
					'label'       => $bad ? __( 'Membership’s scheduled jobs are running late', 'fw' ) : __( 'Membership’s scheduled jobs run on time', 'fw' ),
					'status'      => $bad ? 'recommended' : 'good',
					'badge'       => array( 'label' => __( 'Membership', 'fw' ), 'color' => $bad ? 'orange' : 'blue' ),
					'description' => '<p>' . esc_html__( 'Ending memberships, drip emails and reminders run on WordPress’s scheduler.', 'fw' ) . '</p>',
					'actions'     => $bad ? '<p><code>' . esc_html( self::cron_line() ) . '</code></p>' : '',
					'test'        => 'fw_membership_jobs',
				);
			},
		);
		return $tests;
	}
}
