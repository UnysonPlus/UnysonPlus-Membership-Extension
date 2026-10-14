<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Members change plan themselves (account page → Change plan), to the plans their plan
 * allows (plan settings → "Members can switch to").
 *
 *   Subscription → dearer recurring price   now; the difference is charged now or from
 *                                            the next renewal (Payment settings → proration)
 *   Subscription → cheaper recurring price  at the next renewal (the member keeps what
 *                                            they paid for); Stripe bills the new price
 *   Subscription → free price               at the next renewal: billing stops then and
 *                                            the membership carries on, free
 *   No subscription → free price            now
 *   No subscription → paid price            through the checkout; the old membership ends
 *                                            once the new one is paid (Flow::replace)
 *
 * A change waiting for the renewal is kept on the membership (meta scheduled_change =
 * { plan_id, price_id, at }) and can be undone ("Keep my plan").
 */
class FW_Membership_Plan_Change {

	const META = 'scheduled_change';

	public static function init() {
		add_action( 'unysonplus_membership_account_membership', array( __CLASS__, '_render' ), 10, 2 );
		add_filter( 'unysonplus_membership_account_action', array( __CLASS__, '_handle' ), 10, 3 );
		add_filter( 'unysonplus_membership_account_notices', array( __CLASS__, '_notices' ) );
	}

	/**
	 * What a price costs per day, to tell an upgrade from a downgrade across intervals.
	 *
	 * @param array      $price
	 * @param array|null $plan
	 *
	 * @return float
	 */
	public static function daily( array $price, $plan = null ) {
		if ( 'free' === $price['billing'] ) {
			return 0.0;
		}
		$days = array( 'day' => 1, 'week' => 7, 'month' => 30.4375, 'year' => 365.25 );
		if ( isset( $days[ $price['billing'] ] ) ) {
			return (float) $price['amount'] / ( $days[ $price['billing'] ] * max( 1, (int) $price['interval_count'] ) );
		}
		// One-off: spread over the plan's length (a year when it has none).
		$len = 365.25;
		if ( $plan && $plan['duration_value'] && isset( $days[ $plan['duration_unit'] ] ) ) {
			$len = $days[ $plan['duration_unit'] ] * $plan['duration_value'];
		}
		return (float) $price['amount'] / $len;
	}

	/**
	 * @param array $m
	 *
	 * @return array|null The change waiting for the renewal: { plan_id, price_id, at }.
	 */
	public static function scheduled( array $m ) {
		$s = $m['meta'][ self::META ] ?? null;
		return is_array( $s ) && ! empty( $s['plan_id'] ) ? $s : null;
	}

	/**
	 * @param array $m
	 *
	 * @return bool A move to a free plan is waiting (billing stops at the renewal).
	 */
	public static function scheduled_free( array $m ) {
		$s = self::scheduled( $m );
		if ( ! $s ) {
			return false;
		}
		$price = FW_Membership_Plans::get_price( $s['price_id'] );
		return ! $price || 'free' === $price['billing'] || (float) $price['amount'] <= 0;
	}

	/**
	 * The changes a member may make to one membership.
	 *
	 * @param array $m
	 *
	 * @return array[] { plan, price, kind: upgrade|downgrade, when: now|renewal|checkout, due_now: float|null }
	 */
	public static function options( array $m ) {
		if ( ! in_array( $m['status'], array( 'active', 'trialing' ), true ) || self::scheduled( $m ) ) {
			return array();
		}
		$plan = FW_Membership_Plans::get( $m['plan_id'] );
		if ( ! $plan ) {
			return array();
		}
		$current = $m['price_id'] ? FW_Membership_Plans::get_price( $m['price_id'] ) : null;
		$now_day = $current ? self::daily( $current, $plan ) : 0.0;
		$sub     = '' !== (string) $m['gateway_subscription_id'] && $current && FW_Membership_Payments::is_recurring( $current );
		$gateway = $sub ? FW_Membership_Gateways::get( $m['gateway'] ) : null;
		if ( $sub && ( ! $gateway || ! $gateway->supports_switch() ) ) {
			return array();
		}
		// Plans held directly (a plan only included through another one, such as Supporter
		// inside Gold, is still a valid move: that is the downgrade).
		$held = array_map( function ( $x ) {
			return (int) $x['plan_id'];
		}, FW_Membership_Memberships::query( array( 'user_id' => $m['user_id'], 'status' => 'access', 'per_page' => 50 ) ) );
		$out  = array();
		foreach ( array_map( 'intval', (array) ( $plan['settings']['switch_to'] ?? array() ) ) as $to_id ) {
			$to = FW_Membership_Plans::get( $to_id );
			if ( ! $to || 'active' !== $to['status'] || in_array( $to['id'], $held, true ) || FW_Membership_Plans::is_item( $to ) ) {
				continue;
			}
			$prices = $to['prices'] ? $to['prices'] : array( array( 'id' => 0, 'plan_id' => $to['id'], 'label' => '', 'amount' => 0.0, 'currency' => '', 'billing' => 'free', 'interval_count' => 1, 'trial_days' => 0, 'setup_fee' => 0.0, 'status' => 'active', 'gateway_refs' => array() ) );
			foreach ( $prices as $price ) {
				$free = 'free' === $price['billing'] || ( (float) $price['amount'] <= 0 && ! FW_Membership_Payments::is_recurring( $price ) );
				$day  = self::daily( $price, $to );
				$kind = $day > $now_day ? 'upgrade' : 'downgrade';
				if ( $sub ) {
					if ( ! $free && ( ! FW_Membership_Payments::is_recurring( $price ) || strtoupper( (string) $price['currency'] ) !== strtoupper( (string) $current['currency'] ) ) ) {
						continue; // a subscription moves to another subscription price, in the same currency
					}
					$when = 'upgrade' === $kind && ! $free ? 'now' : 'renewal';
				} else {
					$when = $free ? 'now' : 'checkout';
				}
				$due = null;
				if ( 'now' === $when && $sub && 'prorate' === FW_Membership_Gateways::settings()['proration'] && $m['renews_at'] ) {
					$left = max( 0, strtotime( $m['renews_at'] ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
					$due  = round( max( 0, ( $day - $now_day ) * $left ), 2 );
				}
				$out[] = array( 'plan' => $to, 'price' => $price, 'kind' => $kind, 'when' => $when, 'due_now' => $due );
			}
		}
		return $out;
	}

	/**
	 * Make a change the member chose.
	 *
	 * @param array $m
	 * @param int   $plan_id
	 * @param int   $price_id
	 *
	 * @return array|WP_Error { code: switched|scheduled, redirect?: url }
	 */
	public static function apply( array $m, $plan_id, $price_id ) {
		$opt = null;
		foreach ( self::options( $m ) as $o ) {
			if ( $o['plan']['id'] === (int) $plan_id && (int) $o['price']['id'] === (int) $price_id ) {
				$opt = $o;
				break;
			}
		}
		if ( ! $opt ) {
			return new WP_Error( 'fw_mbr_switch', __( 'That plan change is not available.', 'fw' ) );
		}
		$plan  = $opt['plan'];
		$price = $opt['price'];

		if ( 'checkout' === $opt['when'] ) {
			$url = FW_Membership_Gateways::checkout_url( array( 'plan' => $plan['slug'], 'price' => $price['id'], 'replaces' => $m['id'] ) );
			return '' !== $url ? array( 'code' => 'checkout', 'redirect' => $url ) : new WP_Error( 'fw_mbr_switch', __( 'The checkout page is not set up yet.', 'fw' ) );
		}

		$gateway = '' !== (string) $m['gateway_subscription_id'] ? FW_Membership_Gateways::get( $m['gateway'] ) : null;

		if ( 'now' === $opt['when'] ) {
			if ( $gateway ) {
				$r = $gateway->switch_price( $m, $price, FW_Membership_Gateways::settings()['proration'] );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
			FW_Membership_Service::change_plan( $m['id'], $plan['id'] );
			FW_Membership_Service::set_billing( $m['id'], array( 'price_id' => $price['id'] ) );
			return array( 'code' => 'switched' );
		}

		// At the renewal.
		$free = 'free' === $price['billing'] || (float) $price['amount'] <= 0;
		if ( $gateway ) {
			$r = $free ? $gateway->cancel( $m, false ) : $gateway->switch_price( $m, $price, 'none' );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		$meta              = $m['meta'];
		$meta[ self::META ] = array( 'plan_id' => $plan['id'], 'price_id' => (int) $price['id'], 'at' => (string) ( $m['renews_at'] ? $m['renews_at'] : $m['ends_at'] ) );
		FW_Membership_Memberships::update( $m['id'], array( 'meta' => $meta ) );
		FW_Membership_Memberships::log( array( 'membership_id' => $m['id'], 'user_id' => $m['user_id'], 'plan_id' => $m['plan_id'], 'type' => 'change_scheduled', 'note' => sprintf( __( 'Moves to %s at the next renewal.', 'fw' ), $plan['name'] ) ) );
		return array( 'code' => 'scheduled' );
	}

	/**
	 * Undo a change waiting for the renewal.
	 *
	 * @param array $m
	 *
	 * @return true|WP_Error
	 */
	public static function keep( array $m ) {
		$s = self::scheduled( $m );
		if ( ! $s ) {
			return true;
		}
		$gateway = '' !== (string) $m['gateway_subscription_id'] ? FW_Membership_Gateways::get( $m['gateway'] ) : null;
		if ( $gateway ) {
			$current = FW_Membership_Plans::get_price( $m['price_id'] );
			$r       = self::scheduled_free( $m ) ? $gateway->resume( $m ) : ( $current ? $gateway->switch_price( $m, $current, 'none' ) : true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		self::clear( $m );
		FW_Membership_Memberships::log( array( 'membership_id' => $m['id'], 'user_id' => $m['user_id'], 'plan_id' => $m['plan_id'], 'type' => 'change_undone', 'note' => __( 'The member kept their plan.', 'fw' ) ) );
		return true;
	}

	/**
	 * At a renewal: make the change that was waiting for it.
	 *
	 * @param int $membership_id
	 */
	public static function apply_due( $membership_id ) {
		$m = FW_Membership_Memberships::get( $membership_id );
		$s = $m ? self::scheduled( $m ) : null;
		if ( ! $s ) {
			return;
		}
		self::clear( $m );
		FW_Membership_Service::change_plan( $m['id'], (int) $s['plan_id'] );
		FW_Membership_Service::set_billing( $m['id'], array( 'price_id' => (int) $s['price_id'] ) );
	}

	/**
	 * Billing stopped for a move to a free plan: the membership carries on, free.
	 *
	 * @param array $m
	 */
	public static function to_free_now( array $m ) {
		$s = self::scheduled( $m );
		self::clear( $m );
		$plan = FW_Membership_Plans::get( (int) $s['plan_id'] );
		FW_Membership_Service::change_plan( $m['id'], (int) $s['plan_id'] );
		FW_Membership_Memberships::update( $m['id'], array(
			'status'                  => 'active',
			'price_id'                => (int) $s['price_id'],
			'gateway'                 => '',
			'gateway_subscription_id' => '',
			'renews_at'               => null,
			'cancelled_at'            => null,
			'ends_at'                 => $plan ? FW_Membership_Service::end_date( $plan, current_time( 'mysql' ), '' ) : null,
		) );
	}

	private static function clear( array $m ) {
		$meta = $m['meta'];
		unset( $meta[ self::META ] );
		FW_Membership_Memberships::update( $m['id'], array( 'meta' => $meta ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Account page
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _notices( $n ) {
		$n['switched']  = array( 'success', __( 'Your plan is changed.', 'fw' ) );
		$n['scheduled'] = array( 'success', __( 'Done. Your plan changes at your next renewal; until then nothing changes.', 'fw' ) );
		$n['kept']      = array( 'success', __( 'You are staying on your current plan.', 'fw' ) );
		$n['switch_failed'] = array( 'error', __( 'The plan could not be changed. Please try again, or update your payment method first.', 'fw' ) );
		return $n;
	}

	/** @internal */
	public static function _handle( $code, $action, $user ) {
		if ( ! in_array( $action, array( 'change_plan', 'keep_plan' ), true ) ) {
			return $code;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by FW_Membership_Accounts.
		$m = FW_Membership_Memberships::get( (int) ( $_POST['membership'] ?? 0 ) );
		if ( ! $m || (int) $m['user_id'] !== (int) $user->ID ) {
			return 'failed';
		}
		if ( 'keep_plan' === $action ) {
			return is_wp_error( self::keep( $m ) ) ? 'switch_failed' : 'kept';
		}
		$r = self::apply( $m, (int) ( $_POST['plan'] ?? 0 ), (int) ( $_POST['price'] ?? 0 ) );
		// phpcs:enable
		if ( is_wp_error( $r ) ) {
			set_transient( 'fw_mbr_notice_' . $user->ID, $r->get_error_message(), MINUTE_IN_SECONDS );
			return 'switch_failed';
		}
		if ( ! empty( $r['redirect'] ) ) {
			wp_safe_redirect( $r['redirect'] );
			exit;
		}
		return $r['code'];
	}

	/** @internal */
	public static function _render( $m, $user ) {
		$dfmt = get_option( 'date_format' );
		$s    = self::scheduled( $m );
		if ( $s ) {
			$to = FW_Membership_Plans::get( (int) $s['plan_id'] );
			?>
			<div class="fw-mbr-account__change">
				<p><?php echo esc_html( sprintf( __( 'Moving to %1$s on %2$s.', 'fw' ), $to ? $to['name'] : '', $s['at'] ? date_i18n( $dfmt, strtotime( $s['at'] ) ) : __( 'your next renewal', 'fw' ) ) ); ?></p>
				<form method="post">
					<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
					<input type="hidden" name="fw_mbr_account_action" value="keep_plan" />
					<input type="hidden" name="membership" value="<?php echo (int) $m['id']; ?>" />
					<button type="submit" class="fw-mbr-link-button"><?php esc_html_e( 'Keep my current plan', 'fw' ); ?></button>
				</form>
			</div>
			<?php
			return;
		}
		$opts = self::options( $m );
		if ( ! $opts ) {
			return;
		}
		?>
		<details class="fw-mbr-account__change">
			<summary><?php esc_html_e( 'Change plan', 'fw' ); ?></summary>
			<ul class="fw-mbr-account__options">
				<?php foreach ( $opts as $o ) : ?>
					<?php
					if ( 'now' === $o['when'] ) {
						$note = null !== $o['due_now']
							? sprintf( __( 'Changes now. You pay about %s today for the rest of this period.', 'fw' ), FW_Membership_Plans::money( $o['due_now'], $o['price']['currency'] ) )
							: ( '' !== (string) $m['gateway_subscription_id'] ? __( 'Changes now. The new price applies from your next renewal.', 'fw' ) : __( 'Changes now.', 'fw' ) );
					} elseif ( 'renewal' === $o['when'] ) {
						$note = $m['renews_at'] ? sprintf( __( 'Changes on %s, when your current period ends.', 'fw' ), date_i18n( $dfmt, strtotime( $m['renews_at'] ) ) ) : __( 'Changes at your next renewal.', 'fw' );
					} else {
						$note = __( 'You pay for the new plan at the checkout; your current plan ends once it is paid.', 'fw' );
					}
					?>
					<li>
						<div>
							<strong><?php echo esc_html( $o['plan']['name'] ); ?></strong>
							<span><?php echo esc_html( FW_Membership_Plans::price_label( $o['price'] ) ); ?></span>
							<small><?php echo esc_html( $note ); ?></small>
						</div>
						<form method="post">
							<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
							<input type="hidden" name="fw_mbr_account_action" value="change_plan" />
							<input type="hidden" name="membership" value="<?php echo (int) $m['id']; ?>" />
							<input type="hidden" name="plan" value="<?php echo (int) $o['plan']['id']; ?>" />
							<input type="hidden" name="price" value="<?php echo (int) $o['price']['id']; ?>" />
							<button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class( 'upgrade' !== $o['kind'] ) ); ?>"><?php echo esc_html( 'upgrade' === $o['kind'] ? __( 'Upgrade', 'fw' ) : __( 'Switch', 'fw' ) ); ?></button>
						</form>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}
}
