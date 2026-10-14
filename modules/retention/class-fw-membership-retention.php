<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Retention module's settings (Membership → Settings → Keeping members) and its
 * daily job. The parts:
 *
 *   dunning      failed renewals: grace period, follow-up emails, one-click card update,
 *                expiring-card warnings
 *   pause        members pause for up to N months instead of cancelling
 *   cancel flow  a short "why" survey and one save offer before a cancellation
 *   reminders    renewal / trial-ending / expiring reminders and a win-back coupon
 */
class FW_Membership_Retention {

	const OPTION = 'fw_ext_membership_retention';
	const CRON   = 'fw_mbr_retention_daily';

	public static function init() {
		add_action( 'unysonplus_membership_settings_panels', array( __CLASS__, '_panel' ) );
		add_action( 'unysonplus_membership_save_settings', array( __CLASS__, '_save' ) );
		add_action( self::CRON, array( __CLASS__, 'daily' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( strtotime( 'tomorrow 08:00' ), 'daily', self::CRON );
		}
	}

	/** @return array */
	public static function settings() {
		return array_merge( array(
			'grace_days'      => 3,
			'dunning_days'    => '3,7',
			'card_expiring'   => 1,
			'pause'           => 1,
			'pause_max'       => 3,
			'cancel_flow'     => 1,
			'reasons'         => implode( "\n", array( __( 'It costs too much', 'fw' ), __( 'I do not use it enough', 'fw' ), __( 'It is missing something I need', 'fw' ), __( 'Something did not work', 'fw' ), __( 'I am going elsewhere', 'fw' ), __( 'Another reason', 'fw' ) ) ),
			'offer'           => 'coupon',
			'offer_percent'   => 20,
			'offer_months'    => 3,
			'remind_renewal'  => 7,
			'remind_trial'    => 3,
			'remind_expiring' => 7,
			'winback'         => 1,
			'winback_days'    => 7,
			'winback_percent' => 20,
		), (array) get_option( self::OPTION, array() ) );
	}

	/** @return string[] The cancellation reasons. */
	public static function reasons() {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) self::settings()['reasons'] ) ) ) );
	}

	/** @internal The daily job: reminders, follow-ups, pauses that end. */
	public static function daily() {
		FW_Membership_Pause::resume_due();
		FW_Membership_Dunning::follow_ups();
		FW_Membership_Reminders::run();
	}

	/**
	 * Has a once-only message been sent for this membership + key? Marks it when not.
	 *
	 * @param array  $m
	 * @param string $key e.g. renewal:2026-11-01
	 *
	 * @return bool True the first time (go ahead), false after.
	 */
	public static function once( array $m, $key ) {
		$fresh = FW_Membership_Memberships::get( $m['id'] );
		$meta  = $fresh ? $fresh['meta'] : array();
		$sent  = (array) ( $meta['sent'] ?? array() );
		if ( in_array( $key, $sent, true ) ) {
			return false;
		}
		$sent[]       = $key;
		$meta['sent'] = array_slice( $sent, -30 );
		FW_Membership_Memberships::update( $m['id'], array( 'meta' => $meta ) );
		return true;
	}

	/** @internal */
	public static function _save( $r ) {
		$d = self::settings();
		update_option( self::OPTION, array(
			'grace_days'      => max( 0, min( 30, (int) ( $r['ret_grace_days'] ?? $d['grace_days'] ) ) ),
			'dunning_days'    => implode( ',', array_filter( array_map( 'intval', explode( ',', (string) ( $r['ret_dunning_days'] ?? '' ) ) ) ) ),
			'card_expiring'   => empty( $r['ret_card_expiring'] ) ? 0 : 1,
			'pause'           => empty( $r['ret_pause'] ) ? 0 : 1,
			'pause_max'       => max( 1, min( 12, (int) ( $r['ret_pause_max'] ?? 3 ) ) ),
			'cancel_flow'     => empty( $r['ret_cancel_flow'] ) ? 0 : 1,
			'reasons'         => sanitize_textarea_field( (string) ( $r['ret_reasons'] ?? $d['reasons'] ) ),
			'offer'           => in_array( $r['ret_offer'] ?? '', array( 'none', 'coupon', 'pause', 'downgrade' ), true ) ? $r['ret_offer'] : 'coupon',
			'offer_percent'   => max( 1, min( 100, (int) ( $r['ret_offer_percent'] ?? 20 ) ) ),
			'offer_months'    => max( 1, min( 24, (int) ( $r['ret_offer_months'] ?? 3 ) ) ),
			'remind_renewal'  => max( 0, (int) ( $r['ret_remind_renewal'] ?? 0 ) ),
			'remind_trial'    => max( 0, (int) ( $r['ret_remind_trial'] ?? 0 ) ),
			'remind_expiring' => max( 0, (int) ( $r['ret_remind_expiring'] ?? 0 ) ),
			'winback'         => empty( $r['ret_winback'] ) ? 0 : 1,
			'winback_days'    => max( 1, (int) ( $r['ret_winback_days'] ?? 7 ) ),
			'winback_percent' => max( 1, min( 100, (int) ( $r['ret_winback_percent'] ?? 20 ) ) ),
		), false );
	}

	/** @internal */
	public static function _panel() {
		$s       = self::settings();
		$coupons = class_exists( 'FW_Membership_Coupons' );
		$num     = function ( $name, $value, $label, $desc = '' ) {
			?>
			<p class="fw-mbr-field">
				<label for="fw-mbr-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
				<input type="number" min="0" id="fw-mbr-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $value; ?>" />
				<?php if ( '' !== $desc ) : ?><span class="description"><?php echo esc_html( $desc ); ?></span><?php endif; ?>
			</p>
			<?php
		};
		?>
		<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-ret">
			<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-ret"><?php esc_html_e( 'Keeping members', 'fw' ); ?></h2></header>
			<h3 class="fw-mbr-subhead"><?php esc_html_e( 'When a renewal payment fails', 'fw' ); ?></h3>
			<div class="fw-mbr-fields">
				<?php $num( 'ret_grace_days', $s['grace_days'], __( 'Days of access kept after a failed payment', 'fw' ), __( 'The payment service retries in the meantime.', 'fw' ) ); ?>
				<p class="fw-mbr-field">
					<label for="fw-mbr-ret_dunning_days"><?php esc_html_e( 'Remind them again after (days)', 'fw' ); ?></label>
					<input type="text" id="fw-mbr-ret_dunning_days" name="ret_dunning_days" value="<?php echo esc_attr( $s['dunning_days'] ); ?>" placeholder="3,7" />
					<span class="description"><?php esc_html_e( 'Comma-separated; each email has a one-click link to update the card.', 'fw' ); ?></span>
				</p>
			</div>
			<label class="fw-mbr-check-line"><input type="checkbox" name="ret_card_expiring" value="1" <?php checked( $s['card_expiring'] ); ?> /> <?php esc_html_e( 'Warn members whose card expires before their next renewal (card payments)', 'fw' ); ?></label>

			<h3 class="fw-mbr-subhead"><?php esc_html_e( 'Leaving', 'fw' ); ?></h3>
			<label class="fw-mbr-check-line"><input type="checkbox" name="ret_pause" value="1" <?php checked( $s['pause'] ); ?> /> <?php esc_html_e( 'Members may pause instead of cancelling', 'fw' ); ?></label>
			<div class="fw-mbr-fields"><?php $num( 'ret_pause_max', $s['pause_max'], __( 'Longest pause (months)', 'fw' ) ); ?></div>
			<label class="fw-mbr-check-line"><input type="checkbox" name="ret_cancel_flow" value="1" <?php checked( $s['cancel_flow'] ); ?> /> <?php esc_html_e( 'Ask why and make one offer before a member cancels', 'fw' ); ?></label>
			<div class="fw-mbr-fields">
				<p class="fw-mbr-field fw-mbr-field--wide">
					<label for="fw-mbr-ret_reasons"><?php esc_html_e( 'Reasons to choose from (one per line)', 'fw' ); ?></label>
					<textarea id="fw-mbr-ret_reasons" name="ret_reasons" rows="5"><?php echo esc_textarea( $s['reasons'] ); ?></textarea>
				</p>
				<p class="fw-mbr-field">
					<label for="fw-mbr-ret_offer"><?php esc_html_e( 'Offer before they go', 'fw' ); ?></label>
					<select id="fw-mbr-ret_offer" name="ret_offer">
						<option value="coupon" <?php selected( $s['offer'], 'coupon' ); ?>><?php esc_html_e( 'A discount (card payments)', 'fw' ); ?></option>
						<option value="pause" <?php selected( $s['offer'], 'pause' ); ?>><?php esc_html_e( 'A pause', 'fw' ); ?></option>
						<option value="downgrade" <?php selected( $s['offer'], 'downgrade' ); ?>><?php esc_html_e( 'A cheaper plan (from "Members can switch to")', 'fw' ); ?></option>
						<option value="none" <?php selected( $s['offer'], 'none' ); ?>><?php esc_html_e( 'No offer', 'fw' ); ?></option>
					</select>
				</p>
				<?php $num( 'ret_offer_percent', $s['offer_percent'], __( 'Discount (%)', 'fw' ) ); ?>
				<?php $num( 'ret_offer_months', $s['offer_months'], __( 'For how many months', 'fw' ) ); ?>
			</div>

			<h3 class="fw-mbr-subhead"><?php esc_html_e( 'Reminders (days before; 0 = off)', 'fw' ); ?></h3>
			<div class="fw-mbr-fields">
				<?php $num( 'ret_remind_renewal', $s['remind_renewal'], __( 'Before a renewal', 'fw' ) ); ?>
				<?php $num( 'ret_remind_trial', $s['remind_trial'], __( 'Before a free trial ends', 'fw' ) ); ?>
				<?php $num( 'ret_remind_expiring', $s['remind_expiring'], __( 'Before a membership that will not renew ends', 'fw' ) ); ?>
			</div>
			<label class="fw-mbr-check-line"><input type="checkbox" name="ret_winback" value="1" <?php checked( $s['winback'] ); ?> <?php disabled( ! $coupons ); ?> /> <?php esc_html_e( 'Win them back: after a membership ends, email a one-time discount code', 'fw' ); ?><?php echo $coupons ? '' : ' ' . esc_html__( '(needs the Coupons module)', 'fw' ); ?></label>
			<div class="fw-mbr-fields">
				<?php $num( 'ret_winback_days', $s['winback_days'], __( 'Days after it ends', 'fw' ) ); ?>
				<?php $num( 'ret_winback_percent', $s['winback_percent'], __( 'Discount (%) on the first payment', 'fw' ) ); ?>
			</div>
		</section>
		<?php
	}
}
