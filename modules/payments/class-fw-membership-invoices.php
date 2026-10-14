<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Invoices: every paid payment gets the next number in one unbroken sequence
 * (prefix + number, Payment settings → Invoices), and a printable invoice page the
 * member opens from their account (and saves as PDF from the browser's print dialog,
 * which keeps every language's characters intact).
 *
 *   ?fw_mbr_invoice=<payment id>   for the payment's owner or a membership manager
 *
 * Numbers are given when a payment is paid (never to a pending or failed one) and never
 * reused; a refund keeps the invoice and marks it refunded.
 */
class FW_Membership_Invoices {

	const SEQ = 'fw_ext_membership_invoice_seq';

	public static function init() {
		add_action( 'unysonplus_membership_payment_paid', array( __CLASS__, 'assign' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, '_render' ), 1 );
	}

	/** @return array { prefix, next, business, address, tax_id, footer } */
	public static function settings() {
		$s = (array) ( FW_Membership_Gateways::settings()['invoice'] ?? array() );
		return array_merge( array(
			'prefix'   => 'INV-',
			'business' => get_bloginfo( 'name' ),
			'address'  => '',
			'tax_id'   => '',
			'footer'   => '',
		), $s );
	}

	/**
	 * The next number in the sequence, taken atomically (two payments finishing at the
	 * same moment never share a number).
	 *
	 * @return int
	 */
	public static function next_number() {
		global $wpdb;
		if ( false === get_option( self::SEQ, false ) ) {
			add_option( self::SEQ, '0', '', 'no' );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( option_value + 1 ) WHERE option_name = %s", self::SEQ ) );
		$n = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		wp_cache_delete( self::SEQ, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return $n;
	}

	/**
	 * Give a paid payment its invoice number (once).
	 *
	 * @param array|null $p
	 */
	public static function assign( $p ) {
		if ( ! $p || 'paid' !== $p['status'] || '' !== (string) $p['invoice_number'] || $p['amount'] <= 0 ) {
			return;
		}
		$s = self::settings();
		FW_Membership_Payments::update( $p['id'], array( 'invoice_number' => $s['prefix'] . str_pad( (string) self::next_number(), 5, '0', STR_PAD_LEFT ) ) );
	}

	/**
	 * @param array $p
	 *
	 * @return string The invoice page for a payment ('' when it has no invoice).
	 */
	public static function url( array $p ) {
		return '' !== (string) $p['invoice_number'] ? add_query_arg( 'fw_mbr_invoice', (int) $p['id'], home_url( '/' ) ) : '';
	}

	/** @internal */
	public static function _render() {
		if ( ! isset( $_GET['fw_mbr_invoice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$p = FW_Membership_Payments::get( (int) $_GET['fw_mbr_invoice'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$mine = $p && (int) $p['user_id'] === get_current_user_id();
		if ( ! $p || '' === (string) $p['invoice_number'] || ( ! $mine && ! current_user_can( FW_Membership_Service::capability() ) ) ) {
			wp_die( esc_html__( 'This invoice is not available.', 'fw' ), '', array( 'response' => 404 ) );
		}
		FW_Membership_Cache::no_cache();
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo self::html( $p ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped.
		exit;
	}

	/**
	 * The invoice as a standalone HTML page.
	 *
	 * @param array $p
	 *
	 * @return string
	 */
	public static function html( array $p ) {
		$s     = self::settings();
		$u     = get_userdata( $p['user_id'] );
		$plan  = FW_Membership_Plans::get( $p['plan_id'] );
		$q     = (array) ( $p['meta']['quote'] ?? array() );
		$tax   = (array) ( $p['meta']['tax_info'] ?? array() );
		$money = function ( $n ) use ( $p ) {
			return esc_html( FW_Membership_Plans::money( (float) $n, $p['currency'] ) );
		};
		$date  = date_i18n( get_option( 'date_format' ), strtotime( (string) ( $p['meta']['paid_at'] ?? $p['created_at'] ) ) );
		$what  = ( $plan ? $plan['name'] : __( 'Membership', 'fw' ) ) . ( 'renewal' === $p['type'] ? ' — ' . __( 'renewal', 'fw' ) : '' ) . ( 'plan_change' === ( $p['meta']['reason'] ?? '' ) ? ' — ' . __( 'plan change', 'fw' ) : '' );
		$tax_amount = (float) $p['tax'];
		$total      = (float) $p['amount'];
		$net        = ! empty( $q['tax_inclusive'] ) ? $total : $total - $tax_amount;
		$lines      = '';
		if ( isset( $q['discount'] ) && (float) $q['discount'] > 0 ) {
			$lines .= '<tr><td>' . esc_html( $what ) . '</td><td class="n">' . $money( (float) $q['due'] ) . '</td></tr>';
			$lines .= '<tr><td>' . esc_html( sprintf( __( 'Discount (%s)', 'fw' ), (string) $p['coupon'] ) ) . '</td><td class="n">−' . $money( (float) $q['discount'] ) . '</td></tr>';
		} else {
			$lines .= '<tr><td>' . esc_html( $what ) . '</td><td class="n">' . $money( $net ) . '</td></tr>';
		}
		if ( $tax_amount > 0 ) {
			$label  = (string) ( $q['tax_label'] ?? __( 'Tax', 'fw' ) );
			$lines .= '<tr><td>' . esc_html( ! empty( $q['tax_inclusive'] ) ? sprintf( __( 'Includes %s', 'fw' ), $label ) : $label ) . '</td><td class="n">' . $money( $tax_amount ) . '</td></tr>';
		}
		$buyer = array_filter( array(
			$u ? $u->display_name : '',
			$u ? $u->user_email : '',
			trim( (string) ( $tax['region'] ?? '' ) . ' ' . (string) ( $tax['country'] ?? '' ) ),
			! empty( $tax['vat_number'] ) ? sprintf( __( 'VAT number: %s', 'fw' ), $tax['vat_number'] ) : '',
		) );
		$refunded = 'refunded' === $p['status'];

		ob_start();
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( sprintf( __( 'Invoice %s', 'fw' ), $p['invoice_number'] ) ); ?></title>
<style>
	:root { color-scheme: light; }
	* { box-sizing: border-box; }
	body { margin: 0; background: #f3f4f6; color: #111827; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
	.sheet { max-width: 800px; margin: 32px auto; padding: 48px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
	header { display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; margin-bottom: 40px; }
	h1 { margin: 0 0 4px; font-size: 28px; letter-spacing: -.01em; }
	.muted { color: #6b7280; }
	.from { text-align: right; white-space: pre-line; }
	.meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 32px; }
	.meta h2 { margin: 0 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; }
	.meta p { margin: 0; white-space: pre-line; }
	table { width: 100%; border-collapse: collapse; }
	th, td { padding: 10px 0; border-bottom: 1px solid #e5e7eb; text-align: left; }
	th { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; font-weight: 600; }
	.n { text-align: right; white-space: nowrap; }
	tfoot td { border-bottom: 0; font-weight: 700; font-size: 17px; padding-top: 16px; }
	.stamp { display: inline-block; margin-top: 8px; padding: 2px 10px; border: 2px solid currentColor; border-radius: 4px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; font-size: 12px; }
	.stamp.paid { color: #047857; }
	.stamp.refunded { color: #b91c1c; }
	footer { margin-top: 40px; color: #6b7280; font-size: 13px; white-space: pre-line; }
	.actions { max-width: 800px; margin: 0 auto 32px; text-align: right; }
	.actions button { font: inherit; padding: 8px 16px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; }
	@media print { body { background: #fff; } .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; } .actions { display: none; } }
	@media (max-width: 600px) { .sheet { padding: 24px; margin: 0; border-radius: 0; } .from { text-align: left; } }
</style>
</head>
<body>
<main class="sheet">
	<header>
		<div>
			<h1><?php esc_html_e( 'Invoice', 'fw' ); ?></h1>
			<div class="muted"><?php echo esc_html( $p['invoice_number'] ); ?></div>
			<span class="stamp <?php echo $refunded ? 'refunded' : 'paid'; ?>"><?php echo esc_html( $refunded ? __( 'Refunded', 'fw' ) : __( 'Paid', 'fw' ) ); ?></span>
		</div>
		<div class="from"><strong><?php echo esc_html( $s['business'] ); ?></strong>
<?php echo esc_html( $s['address'] ); ?>
<?php echo '' !== $s['tax_id'] ? esc_html( sprintf( __( 'Tax number: %s', 'fw' ), $s['tax_id'] ) ) : ''; ?></div>
	</header>
	<div class="meta">
		<div><h2><?php esc_html_e( 'Billed to', 'fw' ); ?></h2><p><?php echo esc_html( implode( "\n", $buyer ) ); ?></p></div>
		<div><h2><?php esc_html_e( 'Date', 'fw' ); ?></h2><p><?php echo esc_html( $date ); ?></p></div>
		<div><h2><?php esc_html_e( 'Payment', 'fw' ); ?></h2><p><?php echo esc_html( ( FW_Membership_Gateways::get( $p['gateway'] ) ? FW_Membership_Gateways::get( $p['gateway'] )->title() : $p['gateway'] ) . ( '' !== (string) $p['gateway_ref'] && 'offline' === $p['gateway'] ? "\n" . $p['gateway_ref'] : '' ) ); ?></p></div>
	</div>
	<table>
		<thead><tr><th scope="col"><?php esc_html_e( 'Description', 'fw' ); ?></th><th scope="col" class="n"><?php esc_html_e( 'Amount', 'fw' ); ?></th></tr></thead>
		<tbody><?php echo $lines; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?></tbody>
		<tfoot><tr><td><?php esc_html_e( 'Total', 'fw' ); ?></td><td class="n"><?php echo $money( $total ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr></tfoot>
	</table>
	<?php if ( '' !== trim( (string) $s['footer'] ) ) : ?>
		<footer><?php echo esc_html( $s['footer'] ); ?></footer>
	<?php endif; ?>
</main>
<div class="actions"><button type="button" onclick="window.print()"><?php esc_html_e( 'Print or save as PDF', 'fw' ); ?></button></div>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
