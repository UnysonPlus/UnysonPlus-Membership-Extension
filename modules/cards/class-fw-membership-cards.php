<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Membership cards. Each member with access has a card: on the account page, and full
 * screen at ?fw_mbr_card=1 (made for a phone at the door, and for printing). The QR code
 * holds a signed check link (?fw_mbr_check=<membership id>.<signature>):
 *
 *   anyone who scans it    sees only "Valid membership" or "Not valid" (no name)
 *   staff                  (the Membership capability, or filter
 *                          unysonplus_membership_checkin_capability) also see who it is
 *                          and can record a check-in, kept in the member's history
 *
 * Member numbers are the prefix (Settings → Membership cards) + the account number.
 * The QR is drawn in the browser by the bundled QR generator (MIT).
 */
class FW_Membership_Cards {

	const OPTION = 'fw_ext_membership_cards';
	const NONCE  = 'fw_mbr_checkin';

	public static function init() {
		add_action( 'unysonplus_membership_account_sections', array( __CLASS__, '_account' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, '_card_page' ), 2 );
		add_action( 'template_redirect', array( __CLASS__, '_check_page' ), 2 );
		add_action( 'unysonplus_membership_settings_panels', array( __CLASS__, '_panel' ) );
		add_action( 'unysonplus_membership_save_settings', array( __CLASS__, '_save' ) );
	}

	/** @return array { prefix, color } */
	public static function settings() {
		return array_merge( array( 'prefix' => 'M-', 'color' => '#1f2937' ), (array) get_option( self::OPTION, array() ) );
	}

	/** @return string A member's number. */
	public static function number( $user_id ) {
		return self::settings()['prefix'] . str_pad( (string) (int) $user_id, 6, '0', STR_PAD_LEFT );
	}

	/** @return string The signed check link for a membership. */
	public static function check_url( array $m ) {
		return add_query_arg( 'fw_mbr_check', (int) $m['id'] . '.' . self::sign( $m ), home_url( '/' ) );
	}

	private static function sign( array $m ) {
		return substr( hash_hmac( 'sha256', 'card|' . (int) $m['id'] . '|' . (int) $m['user_id'], wp_salt( 'auth' ) ), 0, 20 );
	}

	/** @return array|null The member's card membership (their main one with access). */
	public static function main( $user_id ) {
		$best = null;
		foreach ( FW_Membership_Memberships::query( array( 'user_id' => (int) $user_id, 'status' => 'access', 'per_page' => 50 ) ) as $m ) {
			$plan = FW_Membership_Plans::get( $m['plan_id'] );
			if ( ! $plan || FW_Membership_Plans::is_item( $plan ) ) {
				continue;
			}
			if ( ! $best || $plan['priority'] > $best['plan']['priority'] ) {
				$best = array( 'm' => $m, 'plan' => $plan );
			}
		}
		return $best;
	}

	/**
	 * The card markup.
	 *
	 * @param WP_User $u
	 * @param array   $main { m, plan }
	 *
	 * @return string
	 */
	public static function card_html( $u, array $main ) {
		$m  = $main['m'];
		$id = 'fw-mbr-qr-' . (int) $m['id'];
		wp_enqueue_script( 'fw-mbr-qrcode', fw_ext( 'membership' )->get_uri( '/static/js/vendor/qrcode.min.js' ), array(), '1.4.4', true );
		ob_start();
		?>
		<div class="fw-mbr-card" style="--fw-mbr-card-bg:<?php echo esc_attr( self::settings()['color'] ); ?>">
			<div class="fw-mbr-card__top">
				<span class="fw-mbr-card__site"><?php echo esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?></span>
				<span class="fw-mbr-card__plan"><?php echo esc_html( $main['plan']['name'] ); ?></span>
			</div>
			<div class="fw-mbr-card__body">
				<div>
					<span class="fw-mbr-card__name"><?php echo esc_html( $u->display_name ); ?></span>
					<span class="fw-mbr-card__number"><?php echo esc_html( self::number( $u->ID ) ); ?></span>
					<span class="fw-mbr-card__valid"><?php echo esc_html( $m['ends_at'] ? sprintf( __( 'Valid until %s', 'fw' ), date_i18n( get_option( 'date_format' ), strtotime( $m['ends_at'] ) ) ) : __( 'Valid while the membership lasts', 'fw' ) ); ?></span>
				</div>
				<div class="fw-mbr-card__qr" id="<?php echo esc_attr( $id ); ?>" data-text="<?php echo esc_attr( self::check_url( $m ) ); ?>" role="img" aria-label="<?php esc_attr_e( 'QR code to check this membership', 'fw' ); ?>"></div>
			</div>
		</div>
		<script>
		( function () {
			function draw() {
				var el = document.getElementById( <?php echo wp_json_encode( $id ); ?> );
				if ( ! el || ! window.qrcode ) { return; }
				var qr = window.qrcode( 0, 'M' );
				qr.addData( el.getAttribute( 'data-text' ) );
				qr.make();
				el.innerHTML = qr.createSvgTag( { cellSize: 4, margin: 2, scalable: true } );
			}
			if ( document.readyState === 'complete' ) { draw(); } else { window.addEventListener( 'load', draw ); }
		}() );
		</script>
		<?php
		return (string) ob_get_clean();
	}

	/** @internal */
	public static function _account( $user ) {
		$main = self::main( $user->ID );
		if ( ! $main ) {
			return;
		}
		?>
		<section class="fw-mbr-account__section" aria-labelledby="fw-mbr-acc-card">
			<h2 id="fw-mbr-acc-card"><?php esc_html_e( 'Your membership card', 'fw' ); ?></h2>
			<?php echo self::card_html( $user, $main ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p><a href="<?php echo esc_url( add_query_arg( 'fw_mbr_card', 1, home_url( '/' ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the card full screen (to show at the door, or print)', 'fw' ); ?></a></p>
		</section>
		<?php
	}

	/** @internal The full-screen card. */
	public static function _card_page() {
		if ( empty( $_GET['fw_mbr_card'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$u    = wp_get_current_user();
		$main = self::main( $u->ID );
		FW_Membership_Cache::no_cache();
		nocache_headers();
		$css = fw_ext( 'membership' )->get_uri( '/static/css/front.css' );
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?php esc_html_e( 'Membership card', 'fw' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( $css ); ?>" />
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f3f4f6;color:#111827;font:16px/1.5 system-ui,sans-serif;padding:16px;box-sizing:border-box}.wrap{width:100%;max-width:420px}.actions{text-align:center;margin-top:16px}@media print{body{background:#fff}.actions{display:none}}</style>
</head>
<body>
<div class="wrap">
	<?php if ( $main ) : ?>
		<?php echo self::card_html( $u, $main ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<p class="actions"><button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'fw' ); ?></button></p>
	<?php else : ?>
		<p><?php esc_html_e( 'You do not have a membership right now.', 'fw' ); ?></p>
	<?php endif; ?>
</div>
<?php wp_print_scripts( 'fw-mbr-qrcode' ); ?>
</body>
</html>
		<?php
		exit;
	}

	/** @return string The capability that may see who a card belongs to and check them in. */
	public static function staff_cap() {
		return (string) apply_filters( 'unysonplus_membership_checkin_capability', FW_Membership_Service::capability() );
	}

	/**
	 * Read a scanned check value.
	 *
	 * @param string $raw "<id>.<signature>"
	 *
	 * @return array|null The membership, when the signature is right.
	 */
	public static function verify( $raw ) {
		$parts = explode( '.', (string) $raw, 2 );
		$m     = 2 === count( $parts ) ? FW_Membership_Memberships::get( (int) $parts[0] ) : null;
		return $m && hash_equals( self::sign( $m ), (string) $parts[1] ) ? $m : null;
	}

	/** @internal The page a scanned QR opens. */
	public static function _check_page() {
		if ( ! isset( $_GET['fw_mbr_check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$m       = self::verify( sanitize_text_field( wp_unslash( $_GET['fw_mbr_check'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$valid   = $m && in_array( $m['status'], FW_Membership_Memberships::ACCESS_STATUSES, true ) && ( ! $m['ends_at'] || strtotime( $m['ends_at'] ) > current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$staff   = current_user_can( self::staff_cap() );
		$checked = false;
		if ( $staff && $m && ! empty( $_POST['fw_mbr_checkin'] ) && check_admin_referer( self::NONCE ) ) {
			FW_Membership_Memberships::log( array( 'membership_id' => $m['id'], 'user_id' => $m['user_id'], 'plan_id' => $m['plan_id'], 'type' => 'check_in', 'note' => $valid ? __( 'Checked in.', 'fw' ) : __( 'Checked in while not valid.', 'fw' ) ) );
			/** A member was checked in at the door. */
			do_action( 'unysonplus_membership_checked_in', $m, get_current_user_id() );
			$checked = true;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		$u    = $m ? get_userdata( $m['user_id'] ) : null;
		$plan = $m ? FW_Membership_Plans::get( $m['plan_id'] ) : null;
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( $valid ? __( 'Valid membership', 'fw' ) : __( 'Not valid', 'fw' ) ); ?></title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:18px/1.5 system-ui,sans-serif;background:<?php echo $valid ? '#065f46' : '#991b1b'; ?>;color:#fff;text-align:center;padding:24px;box-sizing:border-box}h1{font-size:2rem;margin:0 0 .5rem}p{margin:.25rem 0}button{font:inherit;padding:.6rem 1.4rem;border-radius:8px;border:0;margin-top:1rem;cursor:pointer}</style>
</head>
<body><main>
	<h1><?php echo esc_html( $valid ? __( 'Valid membership', 'fw' ) : __( 'Not valid', 'fw' ) ); ?></h1>
	<?php if ( $staff && $m ) : ?>
		<p><strong><?php echo esc_html( $u ? $u->display_name : '' ); ?></strong> · <?php echo esc_html( self::number( $m['user_id'] ) ); ?></p>
		<p><?php echo esc_html( ( $plan ? $plan['name'] : '' ) . ( $m['ends_at'] ? ' · ' . sprintf( __( 'until %s', 'fw' ), date_i18n( get_option( 'date_format' ), strtotime( $m['ends_at'] ) ) ) : '' ) ); ?></p>
		<?php if ( $checked ) : ?>
			<p><?php esc_html_e( 'Checked in.', 'fw' ); ?></p>
		<?php else : ?>
			<form method="post"><?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="fw_mbr_checkin" value="1" /><button type="submit"><?php esc_html_e( 'Check in', 'fw' ); ?></button></form>
		<?php endif; ?>
	<?php elseif ( ! $staff ) : ?>
		<p><?php esc_html_e( 'Staff: log in to see who this is and check them in.', 'fw' ); ?></p>
	<?php endif; ?>
</main></body></html>
		<?php
		exit;
	}

	/** @internal */
	public static function _save( $r ) {
		$color = sanitize_hex_color( (string) ( $r['card_color'] ?? '' ) );
		update_option( self::OPTION, array(
			'prefix' => substr( preg_replace( '/[^A-Za-z0-9-]/', '', (string) ( $r['card_prefix'] ?? 'M-' ) ), 0, 10 ),
			'color'  => $color ? $color : '#1f2937',
		), false );
	}

	/** @internal */
	public static function _panel() {
		$s = self::settings();
		?>
		<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-cards">
			<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-cards"><?php esc_html_e( 'Membership cards', 'fw' ); ?></h2></header>
			<div class="fw-mbr-fields">
				<p class="fw-mbr-field"><label for="fw-mbr-card-prefix"><?php esc_html_e( 'Member number prefix', 'fw' ); ?></label><input type="text" id="fw-mbr-card-prefix" name="card_prefix" class="code" value="<?php echo esc_attr( $s['prefix'] ); ?>" /></p>
				<p class="fw-mbr-field"><label for="fw-mbr-card-color"><?php esc_html_e( 'Card colour', 'fw' ); ?></label><input type="color" id="fw-mbr-card-color" name="card_color" value="<?php echo esc_attr( $s['color'] ); ?>" /></p>
			</div>
			<p class="fw-mbr-muted"><?php esc_html_e( 'Members find their card on their account page. Staff scan the QR code with any phone camera; when logged in with Membership access, they see who it is and can check them in.', 'fw' ); ?></p>
		</section>
		<?php
	}
}
