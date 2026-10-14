<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The cancellation flow: "Cancel membership" opens two short steps on the account page,
 *
 *   1. Why are you leaving? (one reason from the list in Settings, and a comment)
 *   2. One offer: a discount for the next months (card payments), a pause, or a cheaper
 *      plan, then "Keep my membership" or "Cancel anyway".
 *
 * Every answer is kept in the membership's history (cancel_reason, cancel_saved,
 * cancel_confirmed) for Membership → Retention.
 */
class FW_Membership_Cancel_Flow {

	public static function init() {
		add_filter( 'unysonplus_membership_account_cancel_html', array( __CLASS__, '_cancel_html' ), 10, 2 );
		add_filter( 'unysonplus_membership_account_takeover', array( __CLASS__, '_takeover' ), 10, 2 );
		add_filter( 'unysonplus_membership_account_action', array( __CLASS__, '_handle' ), 10, 3 );
		add_filter( 'unysonplus_membership_account_notices', array( __CLASS__, '_notices' ) );
	}

	private static function on() {
		return (bool) FW_Membership_Retention::settings()['cancel_flow'];
	}

	/** @return string The flow's address for a membership. */
	public static function url( array $m, $step = '' ) {
		return add_query_arg( array_filter( array( 'fw_mbr_leave' => (int) $m['id'], 'step' => $step ) ), FW_Membership_Settings::account_url() ) . '#fw-mbr-account';
	}

	/** @internal */
	public static function _cancel_html( $html, $m ) {
		if ( ! self::on() ) {
			return $html;
		}
		return '<p class="fw-mbr-account__cancel"><a class="fw-mbr-link-button" href="' . esc_url( self::url( $m ) ) . '">' . esc_html__( 'Cancel membership', 'fw' ) . '</a></p>';
	}

	/**
	 * The offer for a membership (what it will be, or null for none).
	 *
	 * @param array $m
	 *
	 * @return array|null { type: coupon|pause|downgrade, text, ... }
	 */
	public static function offer( array $m ) {
		$s     = FW_Membership_Retention::settings();
		$order = array_unique( array( $s['offer'], 'pause' ) ); // a pause is the fallback when the chosen offer cannot be made
		foreach ( $order as $type ) {
			if ( 'coupon' === $type && ! empty( $m['meta']['retention_discount'] ) ) {
				continue; // only once per membership
			}
			if ( 'coupon' === $type && '' !== (string) $m['gateway_subscription_id'] ) {
				$g = FW_Membership_Gateways::get( $m['gateway'] );
				if ( $g && 'FW_Membership_Gateway' !== ( new ReflectionMethod( $g, 'discount' ) )->getDeclaringClass()->getName() ) {
					return array( 'type' => 'coupon', 'text' => sprintf( _n( 'Stay, and get %1$d%% off for the next month.', 'Stay, and get %1$d%% off for the next %2$d months.', (int) $s['offer_months'], 'fw' ), (int) $s['offer_percent'], (int) $s['offer_months'] ) );
				}
			}
			if ( 'pause' === $type && class_exists( 'FW_Membership_Pause' ) && FW_Membership_Pause::can( $m ) ) {
				return array( 'type' => 'pause', 'text' => __( 'Need a break? Pause instead: nothing is charged, and your membership waits for you.', 'fw' ) );
			}
			if ( 'downgrade' === $type && class_exists( 'FW_Membership_Plan_Change' ) ) {
				$best = null;
				foreach ( FW_Membership_Plan_Change::options( $m ) as $o ) {
					if ( 'downgrade' === $o['kind'] && ( ! $best || FW_Membership_Plan_Change::daily( $o['price'] ) > FW_Membership_Plan_Change::daily( $best['price'] ) ) && (float) $o['price']['amount'] > 0 ) {
						$best = $o; // the dearest cheaper plan: the smallest step down
					}
				}
				if ( $best ) {
					return array( 'type' => 'downgrade', 'option' => $best, 'text' => sprintf( __( 'Keep going for less: switch to %1$s (%2$s) at your next renewal.', 'fw' ), $best['plan']['name'], FW_Membership_Plans::price_label( $best['price'] ) ) );
				}
			}
		}
		return null;
	}

	/** @internal */
	public static function _takeover( $html, $user ) {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only step selection.
		if ( ! self::on() || ! isset( $_GET['fw_mbr_leave'] ) ) {
			return $html;
		}
		$m    = FW_Membership_Memberships::get( (int) $_GET['fw_mbr_leave'] );
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		// phpcs:enable
		if ( ! $m || (int) $m['user_id'] !== (int) $user->ID || ! in_array( $m['status'], array( 'active', 'trialing' ), true ) ) {
			return $html;
		}
		$plan = FW_Membership_Plans::get( $m['plan_id'] );
		$back = FW_Membership_Settings::account_url();
		ob_start();
		?>
		<section class="fw-mbr-account__section fw-mbr-leave" aria-labelledby="fw-mbr-leave-h">
			<?php if ( 'offer' !== $step ) : ?>
				<h2 id="fw-mbr-leave-h"><?php echo esc_html( sprintf( __( 'Before you cancel %s', 'fw' ), $plan ? $plan['name'] : '' ) ); ?></h2>
				<form method="post" class="fw-mbr-form">
					<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
					<input type="hidden" name="fw_mbr_account_action" value="leave_reason" />
					<input type="hidden" name="membership" value="<?php echo (int) $m['id']; ?>" />
					<fieldset class="fw-mbr-form__row">
						<legend><?php esc_html_e( 'What is the main reason you are leaving?', 'fw' ); ?></legend>
						<?php foreach ( FW_Membership_Retention::reasons() as $i => $reason ) : ?>
							<label class="fw-mbr-leave__reason"><input type="radio" name="reason" value="<?php echo esc_attr( $reason ); ?>" <?php checked( 0, $i ); ?> required /> <?php echo esc_html( $reason ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<p class="fw-mbr-form__row">
						<label for="fw-mbr-leave-comment"><?php esc_html_e( 'Anything you would like to tell us? (optional)', 'fw' ); ?></label>
						<textarea id="fw-mbr-leave-comment" name="comment" rows="3"></textarea>
					</p>
					<p class="fw-mbr-form__actions">
						<button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class( true ) ); ?>"><?php esc_html_e( 'Continue', 'fw' ); ?></button>
						<a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Never mind, keep my membership', 'fw' ); ?></a>
					</p>
				</form>
			<?php else : ?>
				<?php $offer = self::offer( $m ); ?>
				<h2 id="fw-mbr-leave-h"><?php echo esc_html( $offer ? __( 'One last thing', 'fw' ) : __( 'Confirm the cancellation', 'fw' ) ); ?></h2>
				<?php if ( $offer ) : ?>
					<p class="fw-mbr-leave__offer"><?php echo esc_html( $offer['text'] ); ?></p>
					<?php if ( 'pause' === $offer['type'] ) : ?>
						<?php echo FW_Membership_Pause::form( $m, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php else : ?>
						<form method="post">
							<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
							<input type="hidden" name="fw_mbr_account_action" value="leave_accept" />
							<input type="hidden" name="membership" value="<?php echo (int) $m['id']; ?>" />
							<button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class() ); ?>"><?php echo esc_html( 'coupon' === $offer['type'] ? __( 'Yes, keep my membership with the discount', 'fw' ) : __( 'Yes, switch me', 'fw' ) ); ?></button>
						</form>
					<?php endif; ?>
				<?php endif; ?>
				<p><?php echo esc_html( $m['ends_at'] ? sprintf( __( 'If you cancel, you keep access until %s and nothing more is charged.', 'fw' ), date_i18n( get_option( 'date_format' ), strtotime( $m['ends_at'] ) ) ) : __( 'If you cancel, your access ends now.', 'fw' ) ); ?></p>
				<form method="post">
					<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
					<input type="hidden" name="fw_mbr_account_action" value="leave_confirm" />
					<input type="hidden" name="membership" value="<?php echo (int) $m['id']; ?>" />
					<button type="submit" class="fw-mbr-link-button"><?php esc_html_e( 'Cancel anyway', 'fw' ); ?></button>
				</form>
				<p><a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Never mind, keep my membership', 'fw' ); ?></a></p>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** @internal */
	public static function _handle( $code, $action, $user ) {
		if ( ! in_array( $action, array( 'leave_reason', 'leave_accept', 'leave_confirm' ), true ) ) {
			return $code;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by FW_Membership_Accounts.
		$m = FW_Membership_Memberships::get( (int) ( $_POST['membership'] ?? 0 ) );
		if ( ! $m || (int) $m['user_id'] !== (int) $user->ID || ! in_array( $m['status'], array( 'active', 'trialing' ), true ) ) {
			return 'failed';
		}
		$log = function ( $type, $note = '', $meta = array() ) use ( $m ) {
			FW_Membership_Memberships::log( array( 'membership_id' => $m['id'], 'user_id' => $m['user_id'], 'plan_id' => $m['plan_id'], 'type' => $type, 'note' => $note, 'meta' => $meta ) );
		};
		switch ( $action ) {
			case 'leave_reason':
				$reason  = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
				$comment = sanitize_textarea_field( wp_unslash( $_POST['comment'] ?? '' ) );
				$log( 'cancel_reason', $comment, array( 'reason' => $reason ) );
				wp_safe_redirect( self::url( $m, 'offer' ) );
				exit;

			case 'leave_accept':
				$offer = self::offer( $m );
				if ( ! $offer ) {
					return 'failed';
				}
				if ( 'coupon' === $offer['type'] ) {
					$s = FW_Membership_Retention::settings();
					$r = FW_Membership_Gateways::get( $m['gateway'] )->discount( $m, (int) $s['offer_percent'], (int) $s['offer_months'] );
					if ( is_wp_error( $r ) ) {
						return 'failed';
					}
					$meta                       = $m['meta'];
					$meta['retention_discount'] = current_time( 'mysql' );
					FW_Membership_Memberships::update( $m['id'], array( 'meta' => $meta ) );
				} elseif ( 'downgrade' === $offer['type'] ) {
					$r = FW_Membership_Plan_Change::apply( $m, $offer['option']['plan']['id'], $offer['option']['price']['id'] );
					if ( is_wp_error( $r ) ) {
						return 'failed';
					}
				}
				$log( 'cancel_saved', '', array( 'offer' => $offer['type'] ) );
				return 'kept';

			case 'leave_confirm':
				$r = FW_Membership_Service::cancel( $m['id'] );
				$log( 'cancel_confirmed' );
				return is_wp_error( $r ) ? 'failed' : 'cancelled';
		}
		// phpcs:enable
		return $code;
	}

	/** @internal */
	public static function _notices( $n ) {
		$n['kept'] = array( 'success', __( 'Thank you for staying! Your membership carries on.', 'fw' ) );
		return $n;
	}

	/**
	 * Numbers for the Retention screen.
	 *
	 * @param int $days
	 *
	 * @return array { reasons: reason => count, saved: { offer => count }, cancelled, paused, recovered }
	 */
	public static function stats( $days = 90 ) {
		global $wpdb;
		$e     = FW_Membership_Installer::table( 'membership_events' );
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT type, meta FROM {$e} WHERE created_at >= %s AND type IN ('cancel_reason','cancel_saved','cancelled','paused','payment_recovered','payment_failed')", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out   = array( 'reasons' => array(), 'saved' => array(), 'cancelled' => 0, 'paused' => 0, 'recovered' => 0, 'failed' => 0 );
		foreach ( $rows as $r ) {
			$meta = (array) json_decode( (string) $r['meta'], true );
			switch ( $r['type'] ) {
				case 'cancel_reason':
					$k                    = (string) ( $meta['reason'] ?? '' );
					$out['reasons'][ $k ] = ( $out['reasons'][ $k ] ?? 0 ) + 1;
					break;
				case 'cancel_saved':
					$k                  = (string) ( $meta['offer'] ?? '' );
					$out['saved'][ $k ] = ( $out['saved'][ $k ] ?? 0 ) + 1;
					break;
				case 'cancelled':
					$out['cancelled']++;
					break;
				case 'paused':
					$out['paused']++;
					break;
				case 'payment_recovered':
					$out['recovered']++;
					break;
				case 'payment_failed':
					$out['failed']++;
					break;
			}
		}
		arsort( $out['reasons'] );
		return $out;
	}
}
