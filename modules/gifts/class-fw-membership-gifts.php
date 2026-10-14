<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Gift memberships. "Give as a gift" opens the checkout with ?gift=1: the buyer names
 * the recipient and adds a message, and pays once (a repeating price is one period:
 * nothing renews). Once paid, a gift code is made and the recipient gets an email with
 * a redeem link (the checkout page with ?redeem=CODE), where they log in or make an
 * account and the plan starts. A recipient who already holds the plan gets it extended.
 *
 * Table fw_mbr_gifts. The buyer's payment has no membership (membership_id 0).
 */
class FW_Membership_Gifts {

	const VERSION = '1.0.0';
	const NONCE   = 'fw_mbr_redeem';

	/** @var array|null The outcome of a redeem on this request. */
	public static $redeemed = null;

	public static function init() {
		FW_Membership_Installer::module_tables( 'gifts', self::VERSION, array( __CLASS__, 'schema' ), array( 'gifts' ) );
		add_filter( 'unysonplus_membership_quote', array( __CLASS__, '_quote' ), 5, 4 );
		add_filter( 'unysonplus_membership_checkout_start', array( __CLASS__, '_start' ), 10, 7 );
		add_action( 'unysonplus_membership_payment_paid_other', array( __CLASS__, '_paid' ) );
		add_action( 'unysonplus_membership_payment_refunded', array( __CLASS__, '_refunded' ) );
		add_action( 'unysonplus_membership_checkout_fields', array( __CLASS__, '_fields' ), 5, 3 );
		add_filter( 'unysonplus_membership_checkout_takeover', array( __CLASS__, '_redeem_page' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, '_redeem_post' ), 3 );
		add_filter( 'unysonplus_membership_email_catalogue', array( __CLASS__, '_emails' ) );
	}

	/** @internal */
	public static function schema( $collate ) {
		$t = FW_Membership_Installer::table( 'gifts' );
		return array( "CREATE TABLE {$t} (
	id bigint(20) unsigned NOT NULL auto_increment,
	code varchar(40) NOT NULL default '',
	plan_id bigint(20) unsigned NOT NULL default 0,
	price_id bigint(20) unsigned NOT NULL default 0,
	payment_id bigint(20) unsigned NOT NULL default 0,
	buyer_id bigint(20) unsigned NOT NULL default 0,
	recipient_email varchar(190) NOT NULL default '',
	recipient_name varchar(190) NOT NULL default '',
	message text,
	status varchar(20) NOT NULL default 'pending',
	redeemed_by bigint(20) unsigned NOT NULL default 0,
	redeemed_at datetime default NULL,
	membership_id bigint(20) unsigned NOT NULL default 0,
	created_at datetime default NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY code (code),
	KEY payment (payment_id),
	KEY status (status)
) {$collate};" );
	}

	/* ---------------------------------------------------------------------- *
	 * Repository
	 * ---------------------------------------------------------------------- */

	public static function get( $id ) {
		global $wpdb;
		$t = FW_Membership_Installer::table( 'gifts' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function get_by_code( $code ) {
		global $wpdb;
		$t = FW_Membership_Installer::table( 'gifts' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $code ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function by_payment( $payment_id ) {
		global $wpdb;
		$t = FW_Membership_Installer::table( 'gifts' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE payment_id = %d", (int) $payment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function query( $status = '', $limit = 100 ) {
		global $wpdb;
		$t = FW_Membership_Installer::table( 'gifts' );
		$w = '' !== $status ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} {$w} ORDER BY id DESC LIMIT %d", (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function set( $id, array $row ) {
		global $wpdb;
		$wpdb->update( FW_Membership_Installer::table( 'gifts' ), $row, array( 'id' => (int) $id ) );
	}

	/** @return string The recipient's redeem link. */
	public static function redeem_url( $code ) {
		$base = class_exists( 'FW_Membership_Gateways' ) ? FW_Membership_Gateways::checkout_url() : '';
		return add_query_arg( 'redeem', rawurlencode( $code ), '' !== $base ? $base : home_url( '/' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Buying
	 * ---------------------------------------------------------------------- */

	/** @internal A gift is paid once: one period, no trial, nothing renews. */
	public static function _quote( $q, $plan, $price, $args ) {
		if ( empty( $args['gift'] ) ) {
			return $q;
		}
		$q['recurring']  = false;
		$q['trial_days'] = 0;
		$q['due']        = round( $q['amount'] + $q['setup_fee'], 2 );
		$q['gift']       = true;
		return $q;
	}

	/** @internal Run a gift checkout instead of a membership checkout. */
	public static function _start( $pre, $user_id, $plan, $price, $gateway_id, $urls, $args ) {
		if ( null !== $pre || empty( $args['gift'] ) ) {
			return $pre;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- the checkout POST was nonce-checked.
		$email   = sanitize_email( wp_unslash( $_POST['gift_email'] ?? ( $args['gift_email'] ?? '' ) ) );
		$name    = sanitize_text_field( wp_unslash( $_POST['gift_name'] ?? ( $args['gift_name'] ?? '' ) ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['gift_message'] ?? ( $args['gift_message'] ?? '' ) ) );
		// phpcs:enable
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'fw_mbr_gift_email', __( 'Enter the email address of the person the gift is for.', 'fw' ) );
		}
		if ( 'active' !== $plan['status'] || FW_Membership_Plans::is_item( $plan ) ) {
			return new WP_Error( 'fw_mbr_gift_plan', __( 'This plan cannot be given as a gift.', 'fw' ) );
		}
		$q = FW_Membership_Payments_Flow::quote( $plan, $price, array_merge( $args, array( 'user_id' => $user_id ) ) );
		if ( $q['errors'] ) {
			return new WP_Error( 'fw_mbr_quote', implode( ' ', $q['errors'] ) );
		}
		if ( $q['total'] <= 0 ) {
			return new WP_Error( 'fw_mbr_gift_free', __( 'A free plan does not need to be given as a gift: share the sign-up page instead.', 'fw' ) );
		}
		$once    = array_merge( $price, array( 'billing' => 'once', 'trial_days' => 0, 'setup_fee' => 0, 'amount' => $q['total'] ) );
		$gateway = FW_Membership_Gateways::get( $gateway_id );
		if ( ! $gateway || ! isset( FW_Membership_Gateways::available_for( $once )[ $gateway_id ] ) ) {
			return new WP_Error( 'fw_mbr_gateway', __( 'Please choose a way to pay.', 'fw' ) );
		}
		$key = wp_generate_password( 20, false, false );
		$pid = FW_Membership_Payments::insert( array(
			'membership_id' => 0,
			'user_id'       => $user_id,
			'plan_id'       => $plan['id'],
			'price_id'      => $price['id'],
			'type'          => 'payment',
			'status'        => 'pending',
			'amount'        => $q['total'],
			'tax'           => $q['tax'],
			'currency'      => $q['currency'],
			'gateway'       => $gateway_id,
			'coupon'        => $q['coupon'] ? (string) $q['coupon']['code'] : '',
			'meta'          => array( 'key' => $key, 'gift' => 1, 'quote' => array_intersect_key( $q, array_flip( array( 'amount', 'due', 'discount', 'subtotal', 'tax', 'tax_rate', 'tax_label', 'tax_inclusive', 'total' ) ) ), 'tax_info' => array_intersect_key( $args, array_flip( array( 'country', 'region', 'vat_number' ) ) ) ),
		) );
		global $wpdb;
		$code = 'GIFT-' . strtoupper( wp_generate_password( 10, false, false ) );
		$wpdb->insert( FW_Membership_Installer::table( 'gifts' ), array(
			'code'            => $code,
			'plan_id'         => $plan['id'],
			'price_id'        => $price['id'],
			'payment_id'      => $pid,
			'buyer_id'        => $user_id,
			'recipient_email' => $email,
			'recipient_name'  => $name,
			'message'         => $message,
			'status'          => 'pending',
			'created_at'      => current_time( 'mysql' ),
		) );
		$urls = array(
			'success' => add_query_arg( array( 'fw_mbr_payment' => $pid, 'k' => $key ), $urls['success'] ),
			'cancel'  => add_query_arg( array( 'fw_mbr_payment' => $pid, 'k' => $key, 'fw_mbr_cancelled' => 1 ), $urls['cancel'] ),
		);
		$result = $gateway->start( FW_Membership_Payments::get( $pid ), array( 'id' => 0, 'plan_id' => $plan['id'], 'user_id' => $user_id ), $once, get_userdata( $user_id ), $urls );
		if ( is_wp_error( $result ) ) {
			FW_Membership_Payments::update( $pid, array( 'status' => 'failed' ) );
			return $result;
		}
		return array_merge( (array) $result, array( 'payment_id' => $pid, 'membership_id' => 0, 'key' => $key ) );
	}

	/** @internal The gift was paid: send it. */
	public static function _paid( $p ) {
		$g = $p ? self::by_payment( $p['id'] ) : null;
		if ( ! $g || 'pending' !== $g['status'] ) {
			return;
		}
		self::set( $g['id'], array( 'status' => 'sent' ) );
		self::send( $g );
		if ( '' !== (string) $p['coupon'] ) {
			do_action( 'unysonplus_membership_checkout_completed', array( 'coupon' => array( 'code' => $p['coupon'] ) ), $p['user_id'], 0, $p['id'] );
		}
	}

	/** Email the recipient (and confirm to the buyer). */
	public static function send( array $g ) {
		$plan  = FW_Membership_Plans::get( $g['plan_id'] );
		$buyer = get_userdata( (int) $g['buyer_id'] );
		$vars  = array(
			'plan'           => $plan ? $plan['name'] : '',
			'recipient_name' => '' !== $g['recipient_name'] ? $g['recipient_name'] : __( 'there', 'fw' ),
			'buyer_name'     => $buyer ? $buyer->display_name : '',
			'gift_message'   => (string) $g['message'],
			'redeem_url'     => self::redeem_url( $g['code'] ),
			'gift_code'      => $g['code'],
		);
		FW_Membership_Emails::send( 'gift_received', (int) $g['buyer_id'], $vars, false, $g['recipient_email'] );
		FW_Membership_Emails::send( 'gift_sent', (int) $g['buyer_id'], array_merge( $vars, array( 'recipient_email' => $g['recipient_email'] ) ) );
	}

	/** @internal A refunded, unredeemed gift is cancelled. */
	public static function _refunded( $p ) {
		$g = $p ? self::by_payment( $p['id'] ) : null;
		if ( $g && 'redeemed' !== $g['status'] ) {
			self::set( $g['id'], array( 'status' => 'cancelled' ) );
		}
	}

	/** @internal Recipient fields in the gift checkout. */
	public static function _fields( $plan, $price, $quote ) {
		if ( empty( $_REQUEST['gift'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		?>
		<fieldset class="fw-mbr-form__row fw-mbr-gift">
			<legend><?php esc_html_e( 'Who is the gift for?', 'fw' ); ?></legend>
			<p class="fw-mbr-form__row fw-mbr-form__row--half">
				<label for="fw-mbr-gift-name"><?php esc_html_e( 'Their name', 'fw' ); ?></label>
				<input type="text" id="fw-mbr-gift-name" name="gift_name" />
			</p>
			<p class="fw-mbr-form__row fw-mbr-form__row--half">
				<label for="fw-mbr-gift-email"><?php esc_html_e( 'Their email', 'fw' ); ?> <span aria-hidden="true">*</span></label>
				<input type="email" id="fw-mbr-gift-email" name="gift_email" required />
			</p>
			<p class="fw-mbr-form__row">
				<label for="fw-mbr-gift-msg"><?php esc_html_e( 'A message (optional)', 'fw' ); ?></label>
				<textarea id="fw-mbr-gift-msg" name="gift_message" rows="3"></textarea>
			</p>
			<p class="fw-mbr-muted"><?php esc_html_e( 'They get an email with a link to start their membership once your payment goes through. You pay once; it does not renew.', 'fw' ); ?></p>
		</fieldset>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Redeeming
	 * ---------------------------------------------------------------------- */

	/**
	 * Start a gift for a user.
	 *
	 * @param array $g
	 * @param int   $user_id
	 *
	 * @return int|WP_Error The membership id.
	 */
	public static function redeem( array $g, $user_id ) {
		if ( 'sent' !== $g['status'] ) {
			return new WP_Error( 'fw_mbr_gift_used', 'redeemed' === $g['status'] ? __( 'This gift has already been used.', 'fw' ) : __( 'This gift is not ready yet, or was cancelled.', 'fw' ) );
		}
		$plan  = FW_Membership_Plans::get( $g['plan_id'] );
		$price = FW_Membership_Plans::get_price( $g['price_id'] );
		if ( ! $plan ) {
			return new WP_Error( 'fw_mbr_gift_plan', __( 'The plan of this gift no longer exists.', 'fw' ) );
		}
		// One period of a repeating price; the plan's own length otherwise.
		$until = '';
		if ( $price && FW_Membership_Payments::is_recurring( $price ) ) {
			$until = '+' . max( 1, (int) $price['interval_count'] ) . ' ' . $price['billing'] . 's';
		}
		$held = FW_Membership_Memberships::query( array( 'user_id' => (int) $user_id, 'plan_id' => $plan['id'], 'status' => 'access', 'per_page' => 1 ) );
		if ( $held ) {
			$r   = FW_Membership_Service::extend( $held[0]['id'], $until );
			$mid = is_wp_error( $r ) ? $r : $held[0]['id'];
		} else {
			$mid = FW_Membership_Service::grant( (int) $user_id, $plan['id'], array(
				'source'     => 'gift',
				'source_ref' => $g['code'],
				'price_id'   => $g['price_id'],
				'notify'     => true,
				'ends'       => '' !== $until ? gmdate( 'Y-m-d H:i:s', strtotime( $until, current_time( 'timestamp' ) ) ) : '', // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			) );
		}
		if ( is_wp_error( $mid ) ) {
			return $mid;
		}
		self::set( $g['id'], array( 'status' => 'redeemed', 'redeemed_by' => (int) $user_id, 'redeemed_at' => current_time( 'mysql' ), 'membership_id' => (int) $mid ) );
		return (int) $mid;
	}

	/** @internal The redeem form's POST. */
	public static function _redeem_post() {
		if ( empty( $_POST['fw_mbr_redeem'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( self::NONCE );
		$g = self::get_by_code( sanitize_text_field( wp_unslash( $_POST['fw_mbr_redeem'] ) ) );
		if ( ! $g ) {
			self::$redeemed = array( 'error' => __( 'That gift code is not valid.', 'fw' ) );
			return;
		}
		$uid = get_current_user_id();
		if ( ! $uid ) {
			$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$pass  = (string) wp_unslash( $_POST['password'] ?? '' );
			if ( ! is_email( $email ) || strlen( $pass ) < 8 ) {
				self::$redeemed = array( 'error' => __( 'Enter your email and a password of at least 8 characters.', 'fw' ) );
				return;
			}
			if ( email_exists( $email ) ) {
				self::$redeemed = array( 'error' => __( 'An account with this email already exists. Please log in first, then come back to this page.', 'fw' ) );
				return;
			}
			$uid = wp_insert_user( array( 'user_login' => FW_Membership_Checkout::username( $email ), 'user_email' => $email, 'user_pass' => $pass, 'first_name' => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ), 'role' => get_option( 'default_role', 'subscriber' ) ) );
			if ( is_wp_error( $uid ) ) {
				self::$redeemed = array( 'error' => $uid->get_error_message() );
				return;
			}
			wp_set_current_user( $uid );
			wp_set_auth_cookie( $uid, true );
		}
		$r = self::redeem( $g, $uid );
		if ( is_wp_error( $r ) ) {
			self::$redeemed = array( 'error' => $r->get_error_message() );
			return;
		}
		wp_safe_redirect( add_query_arg( 'fw_mbr_notice', 'joined', FW_Membership_Settings::account_url() ) );
		exit;
	}

	/** @internal The redeem page (the checkout page with ?redeem=CODE). */
	public static function _redeem_page( $html, $atts, $outcome ) {
		if ( ! isset( $_GET['redeem'] ) && empty( $_POST['fw_mbr_redeem'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $html;
		}
		$code = sanitize_text_field( wp_unslash( $_REQUEST['redeem'] ?? ( $_POST['fw_mbr_redeem'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$g    = self::get_by_code( $code );
		$plan = $g ? FW_Membership_Plans::get( $g['plan_id'] ) : null;
		ob_start();
		if ( ! empty( self::$redeemed['error'] ) ) {
			echo '<p class="fw-mbr-notice fw-mbr-notice--error" role="alert">' . esc_html( self::$redeemed['error'] ) . '</p>';
		}
		if ( ! $g || ! $plan || 'sent' !== $g['status'] ) {
			echo '<p class="fw-mbr-notice">' . esc_html( $g && 'redeemed' === $g['status'] ? __( 'This gift has already been used.', 'fw' ) : __( 'That gift code is not valid.', 'fw' ) ) . '</p>';
			return (string) ob_get_clean();
		}
		$buyer = get_userdata( (int) $g['buyer_id'] );
		?>
		<div class="fw-mbr-checkout__summary">
			<div><strong><?php echo esc_html( sprintf( __( 'A gift: %s', 'fw' ), $plan['name'] ) ); ?></strong>
				<span><?php echo esc_html( $buyer ? sprintf( __( 'From %s', 'fw' ), $buyer->display_name ) : '' ); ?></span></div>
		</div>
		<?php if ( '' !== trim( (string) $g['message'] ) ) : ?>
			<blockquote class="fw-mbr-gift__message"><?php echo esc_html( $g['message'] ); ?></blockquote>
		<?php endif; ?>
		<form method="post" class="fw-mbr-form">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="fw_mbr_redeem" value="<?php echo esc_attr( $g['code'] ); ?>" />
			<?php if ( ! is_user_logged_in() ) : ?>
				<p class="fw-mbr-form__row"><?php esc_html_e( 'Already have an account?', 'fw' ); ?> <a href="<?php echo esc_url( FW_Membership_Settings::login_url( self::redeem_url( $g['code'] ) ) ); ?>"><?php esc_html_e( 'Log in first', 'fw' ); ?></a></p>
				<p class="fw-mbr-form__row"><label for="fw-mbr-rd-first"><?php esc_html_e( 'First name', 'fw' ); ?></label><input type="text" id="fw-mbr-rd-first" name="first_name" value="<?php echo esc_attr( $g['recipient_name'] ); ?>" /></p>
				<p class="fw-mbr-form__row"><label for="fw-mbr-rd-email"><?php esc_html_e( 'Email', 'fw' ); ?></label><input type="email" id="fw-mbr-rd-email" name="email" value="<?php echo esc_attr( $g['recipient_email'] ); ?>" required /></p>
				<p class="fw-mbr-form__row"><label for="fw-mbr-rd-pass"><?php esc_html_e( 'Choose a password', 'fw' ); ?></label><input type="password" id="fw-mbr-rd-pass" name="password" minlength="8" autocomplete="new-password" required /></p>
			<?php else : ?>
				<p class="fw-mbr-form__row"><?php echo esc_html( sprintf( __( 'Starting it on your account (%s).', 'fw' ), wp_get_current_user()->user_email ) ); ?></p>
			<?php endif; ?>
			<p class="fw-mbr-form__actions"><button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class() ); ?>"><?php esc_html_e( 'Start my membership', 'fw' ); ?></button></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/** @internal */
	public static function _emails( $c ) {
		$c['gift_received'] = array(
			'label'   => __( 'Gift received', 'fw' ),
			'to'      => 'member',
			'when'    => __( 'To the person a gift is for, once it is paid.', 'fw' ),
			'subject' => __( '{buyer_name} gave you a {plan} membership', 'fw' ),
			'body'    => __( "Hi {recipient_name},\n\n{buyer_name} has given you a {plan} membership at {site_name}.\n\n{gift_message}\n\nStart it here:\n{redeem_url}\n\n(Your gift code: {gift_code})", 'fw' ),
		);
		$c['gift_sent'] = array(
			'label'   => __( 'Gift sent', 'fw' ),
			'to'      => 'member',
			'when'    => __( 'To the buyer, once their gift is paid and sent.', 'fw' ),
			'subject' => __( 'Your gift of {plan} is on its way', 'fw' ),
			'body'    => __( "Hi {first_name},\n\nThank you! We have emailed your gift of {plan} to {recipient_email}. If it does not arrive, they can use this link or the code {gift_code}:\n\n{redeem_url}", 'fw' ),
		);
		return $c;
	}
}
