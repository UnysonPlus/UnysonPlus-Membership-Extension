<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Pause instead of cancel: a member pauses for 1 to N months. Billing stops at the
 * payment service (Stripe resumes by itself on the day; PayPal and memberships without a
 * subscription are resumed by the daily job), access stops, and both come back on the
 * date. A membership without a subscription gets the paused time added to its end date.
 */
class FW_Membership_Pause {

	public static function init() {
		add_filter( 'unysonplus_membership_account_action', array( __CLASS__, '_handle' ), 10, 3 );
		add_filter( 'unysonplus_membership_account_notices', array( __CLASS__, '_notices' ) );
		add_action( 'unysonplus_membership_account_membership', array( __CLASS__, '_render' ), 5, 2 );
		add_filter( 'unysonplus_membership_email_catalogue', array( __CLASS__, '_emails' ) );
		add_action( 'unysonplus_membership_paused', array( __CLASS__, '_paused_email' ), 20, 2 );
	}

	/** @return bool May this membership be paused? */
	public static function can( array $m ) {
		if ( ! FW_Membership_Retention::settings()['pause'] || ! in_array( $m['status'], array( 'active', 'trialing' ), true ) || ! $m['ends_at'] ) {
			return false;
		}
		if ( '' !== (string) $m['gateway_subscription_id'] ) {
			$g = FW_Membership_Gateways::get( $m['gateway'] );
			// Only a gateway that implements pause() itself can stop its billing.
			return $g && 'FW_Membership_Gateway' !== ( new ReflectionMethod( $g, 'pause' ) )->getDeclaringClass()->getName();
		}
		return true;
	}

	/**
	 * Pause for some months.
	 *
	 * @param array $m
	 * @param int   $months
	 *
	 * @return true|WP_Error
	 */
	public static function pause( array $m, $months ) {
		$months = max( 1, min( (int) FW_Membership_Retention::settings()['pause_max'], (int) $months ) );
		if ( ! self::can( $m ) ) {
			return new WP_Error( 'fw_mbr_pause', __( 'This membership cannot be paused.', 'fw' ) );
		}
		$until = strtotime( '+' . $months . ' months', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		if ( '' !== (string) $m['gateway_subscription_id'] ) {
			$r = FW_Membership_Gateways::get( $m['gateway'] )->pause( $m, (int) get_gmt_from_date( gmdate( 'Y-m-d H:i:s', $until ), 'U' ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		return FW_Membership_Service::pause( $m['id'], gmdate( 'Y-m-d H:i:s', $until ) );
	}

	/**
	 * End a pause now (the member's "Resume now", or the date arrived).
	 *
	 * @param array $m
	 *
	 * @return true|WP_Error
	 */
	public static function resume( array $m ) {
		if ( '' !== (string) $m['gateway_subscription_id'] ) {
			$g = FW_Membership_Gateways::get( $m['gateway'] );
			$r = $g ? $g->unpause( $m ) : true;
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		return FW_Membership_Service::resume( $m['id'] );
	}

	/** Daily: pauses whose date has come. */
	public static function resume_due() {
		$now = current_time( 'mysql' );
		foreach ( FW_Membership_Memberships::query( array( 'status' => array( 'paused' ), 'per_page' => 500 ) ) as $m ) {
			if ( ! empty( $m['meta']['paused_until'] ) && $m['meta']['paused_until'] <= $now ) {
				self::resume( $m );
			}
		}
	}

	/** @internal */
	public static function _handle( $code, $action, $user ) {
		if ( ! in_array( $action, array( 'pause', 'unpause' ), true ) ) {
			return $code;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by FW_Membership_Accounts.
		$m = FW_Membership_Memberships::get( (int) ( $_POST['membership'] ?? 0 ) );
		if ( ! $m || (int) $m['user_id'] !== (int) $user->ID ) {
			return 'failed';
		}
		$r = 'pause' === $action ? self::pause( $m, (int) ( $_POST['months'] ?? 1 ) ) : self::resume( $m );
		// phpcs:enable
		return is_wp_error( $r ) ? 'failed' : ( 'pause' === $action ? 'paused' : 'resumed' );
	}

	/** @internal */
	public static function _notices( $n ) {
		$n['paused']  = array( 'success', __( 'Your membership is paused. Nothing is charged until it resumes.', 'fw' ) );
		$n['resumed'] = array( 'success', __( 'Welcome back! Your membership is running again.', 'fw' ) );
		return $n;
	}

	/** @internal The pause controls under a membership. */
	public static function _render( $m, $user ) {
		if ( 'paused' === $m['status'] ) {
			?>
			<div class="fw-mbr-account__change">
				<p><?php echo esc_html( sprintf( __( 'Paused until %s.', 'fw' ), ! empty( $m['meta']['paused_until'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $m['meta']['paused_until'] ) ) : '—' ) ); ?></p>
				<form method="post">
					<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
					<input type="hidden" name="fw_mbr_account_action" value="unpause" />
					<input type="hidden" name="membership" value="<?php echo (int) $m['id']; ?>" />
					<button type="submit" class="fw-mbr-link-button"><?php esc_html_e( 'Resume now', 'fw' ); ?></button>
				</form>
			</div>
			<?php
			return;
		}
		if ( ! self::can( $m ) || FW_Membership_Retention::settings()['cancel_flow'] ) {
			return; // with the cancel flow on, the pause is offered there
		}
		echo self::form( $m ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * The "pause for N months" form.
	 *
	 * @param array $m
	 * @param bool  $primary A main button (the cancellation offer) instead of a link.
	 *
	 * @return string
	 */
	public static function form( array $m, $primary = false ) {
		$max = (int) FW_Membership_Retention::settings()['pause_max'];
		ob_start();
		?>
		<form method="post" class="fw-mbr-account__pause">
			<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
			<input type="hidden" name="fw_mbr_account_action" value="pause" />
			<input type="hidden" name="membership" value="<?php echo (int) $m['id']; ?>" />
			<label for="fw-mbr-pause-<?php echo (int) $m['id']; ?>"><?php esc_html_e( 'Pause for', 'fw' ); ?></label>
			<select id="fw-mbr-pause-<?php echo (int) $m['id']; ?>" name="months">
				<?php for ( $i = 1; $i <= $max; $i++ ) : ?>
					<option value="<?php echo (int) $i; ?>"><?php echo esc_html( sprintf( _n( '%d month', '%d months', $i, 'fw' ), $i ) ); ?></option>
				<?php endfor; ?>
			</select>
			<button type="submit" class="<?php echo esc_attr( $primary ? FW_Membership_Settings::button_class() : 'fw-mbr-link-button' ); ?>"><?php echo esc_html( $primary ? __( 'Pause my membership', 'fw' ) : __( 'Pause', 'fw' ) ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/** @internal */
	public static function _emails( $c ) {
		$c['paused'] = array(
			'label'   => __( 'Membership paused', 'fw' ),
			'to'      => 'member',
			'when'    => __( 'When a member pauses.', 'fw' ),
			'subject' => __( 'Your {plan} membership is paused', 'fw' ),
			'body'    => __( "Hi {first_name},\n\nYour {plan} membership is paused until {paused_until}. Nothing is charged meanwhile, and it starts again by itself on that day. You can resume earlier from your account:\n\n{account_url}", 'fw' ),
		);
		return $c;
	}

	/** @internal */
	public static function _paused_email( $m, $ctx ) {
		$plan = FW_Membership_Plans::get( $m['plan_id'] );
		FW_Membership_Emails::send( 'paused', $m['user_id'], array(
			'plan'         => $plan ? $plan['name'] : '',
			'paused_until' => ! empty( $m['meta']['paused_until'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $m['meta']['paused_until'] ) ) : '',
		) );
	}
}
