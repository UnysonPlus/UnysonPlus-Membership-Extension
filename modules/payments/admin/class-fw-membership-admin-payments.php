<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Membership → Payments: every payment, renewal and refund, with Mark paid (bank
 * transfer), Refund, and the payment settings (currency, checkout page, gateways).
 */
class FW_Membership_Admin_Payments {

	public static function init() {
		add_action( 'unysonplus_membership_render_view', array( __CLASS__, '_render' ) );
		add_filter( 'unysonplus_membership_admin_action', array( __CLASS__, '_handle' ), 10, 2 );
	}

	/** @internal */
	public static function _handle( $back, $action ) {
		// phpcs:disable WordPress.Security.NonceVerification -- verified by the admin page before dispatch.
		$r = wp_unslash( $_REQUEST );
		switch ( $action ) {
			case 'save_payment_settings':
				FW_Membership_Gateways::save_settings( (array) $r );
				FW_Membership_Admin_Page::flash( 'success', __( 'Payment settings saved.', 'fw' ) );
				return array( 'tab' => 'payments', 'view' => 'settings' );

			case 'mark_paid':
				$p = FW_Membership_Payments::get( (int) ( $r['id'] ?? 0 ) );
				if ( $p && 'pending' === $p['status'] ) {
					FW_Membership_Payments_Flow::paid( $p['id'], array( 'meta' => array( 'marked_by' => get_current_user_id() ) ) );
					FW_Membership_Admin_Page::flash( 'success', __( 'Marked as paid. The membership has started and the member was emailed a receipt.', 'fw' ) );
				}
				return array( 'tab' => 'payments' );

			case 'refund_payment':
				$p = FW_Membership_Payments::get( (int) ( $r['id'] ?? 0 ) );
				if ( $p && 'paid' === $p['status'] ) {
					$g   = FW_Membership_Gateways::get( $p['gateway'] );
					$res = ! empty( $r['only_mark'] ) || ! $g ? true : $g->refund( $p );
					if ( is_wp_error( $res ) ) {
						FW_Membership_Admin_Page::flash( 'error', $res->get_error_message() );
					} else {
						FW_Membership_Payments_Flow::refunded( $p['id'], 'payment' === $p['type'] );
						FW_Membership_Admin_Page::flash( 'success', 'payment' === $p['type'] ? __( 'Refunded. The membership has ended.', 'fw' ) : __( 'Refunded. The membership continues; cancel it on the member\'s page if it should stop.', 'fw' ) );
					}
				}
				return array( 'tab' => 'payments' );
		}
		// phpcs:enable
		return $back;
	}

	/** @internal */
	public static function _render( $tab ) {
		if ( 'payments' !== $tab ) {
			return;
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'settings' === $view ) {
			self::render_settings();
			return;
		}
		self::render_list();
	}

	private static function render_list() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		// phpcs:enable
		$base   = FW_Membership_Admin_Page::get_page_url( 'payments' );
		$ready  = array_filter( FW_Membership_Gateways::all(), function ( $g ) {
			return $g->is_enabled() && $g->is_ready();
		} );
		$args   = array( 'status' => $status, 'per_page' => 30, 'page' => $paged );
		$rows   = FW_Membership_Payments::query( $args );
		$total  = FW_Membership_Payments::count( $args );
		$month  = FW_Membership_Payments::totals( gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$labels = array( 'pending' => __( 'Waiting', 'fw' ), 'paid' => __( 'Paid', 'fw' ), 'failed' => __( 'Failed', 'fw' ), 'refunded' => __( 'Refunded', 'fw' ), 'cancelled' => __( 'Cancelled', 'fw' ) );
		$pill   = array( 'pending' => 'pending', 'paid' => 'active', 'failed' => 'refunded', 'refunded' => 'expired', 'cancelled' => 'expired' );
		?>
		<?php if ( ! $ready ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'No payment method is set up yet, so plans with a price cannot be bought.', 'fw' ); ?> <a href="<?php echo esc_url( add_query_arg( 'view', 'settings', $base ) ); ?>"><?php esc_html_e( 'Set up payments', 'fw' ); ?></a></p></div>
		<?php endif; ?>
		<div class="fw-mbr-listbar">
			<p class="fw-mbr-status-line">
				<?php
				if ( $month ) {
					$parts = array();
					foreach ( $month as $cur => $sum ) {
						$parts[] = FW_Membership_Payments::money( $sum, $cur );
					}
					echo esc_html( sprintf( __( 'Taken in the last 30 days: %s.', 'fw' ), implode( ', ', $parts ) ) );
				} else {
					esc_html_e( 'No payments in the last 30 days.', 'fw' );
				}
				?>
			</p>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'view', 'settings', $base ) ); ?>"><?php esc_html_e( 'Payment settings', 'fw' ); ?></a>
		</div>
		<ul class="subsubsub fw-mbr-views">
			<li><a href="<?php echo esc_url( $base ); ?>"<?php echo '' === $status ? ' class="current"' : ''; ?>><?php esc_html_e( 'All', 'fw' ); ?></a> |</li>
			<?php $i = 0; foreach ( $labels as $s => $l ) : $i++; ?>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', $s, $base ) ); ?>"<?php echo $status === $s ? ' class="current"' : ''; ?>><?php echo esc_html( $l ); ?></a><?php echo $i < count( $labels ) ? ' |' : ''; ?></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( ! $rows ) : ?>
			<?php FW_Membership_App::empty_state( 'dashicons-money-alt', __( 'No payments yet', 'fw' ), __( 'Payments appear here as people buy plans through your checkout page: first payments, renewals and refunds.', 'fw' ) ); ?>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped fw-mbr-payments-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Date', 'fw' ); ?></th>
					<th scope="col" class="column-primary"><?php esc_html_e( 'Member', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Plan', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Method', 'fw' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $rows as $p ) : ?>
						<?php
						$u    = get_userdata( $p['user_id'] );
						$plan = FW_Membership_Plans::get( $p['plan_id'] );
						$g    = FW_Membership_Gateways::get( $p['gateway'] );
						?>
						<tr>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $p['created_at'] ) ) ); ?></td>
							<td class="column-primary">
								<?php if ( $u ) : ?>
									<a href="<?php echo esc_url( FW_Membership_Admin_Page::get_page_url( 'members', array( 'member' => $u->ID ) ) ); ?>"><?php echo esc_html( $u->display_name ); ?></a>
								<?php else : ?>
									<?php esc_html_e( '(deleted)', 'fw' ); ?>
								<?php endif; ?>
								<div class="row-actions">
									<?php if ( 'pending' === $p['status'] && 'offline' === $p['gateway'] ) : ?>
										<span><a href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'mark_paid', array( 'id' => $p['id'] ), 'payments' ) ); ?>"><?php esc_html_e( 'Mark paid', 'fw' ); ?></a></span>
									<?php elseif ( 'paid' === $p['status'] ) : ?>
										<span class="trash"><a href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'refund_payment', array( 'id' => $p['id'] ), 'payments' ) ); ?>" onclick="return confirm('<?php echo esc_js( 'stripe' === $p['gateway'] ? __( 'Refund this payment through Stripe? A refunded first payment ends the membership.', 'fw' ) : __( 'Mark this payment refunded? Return the money yourself. A refunded first payment ends the membership.', 'fw' ) ); ?>');"><?php esc_html_e( 'Refund', 'fw' ); ?></a></span>
									<?php endif; ?>
								</div>
							</td>
							<td><?php echo $plan ? esc_html( $plan['name'] ) : '—'; ?><?php echo 'renewal' === $p['type'] ? ' <span class="fw-mbr-muted">' . esc_html__( '(renewal)', 'fw' ) . '</span>' : ''; ?></td>
							<td><?php echo esc_html( FW_Membership_Payments::money( $p['amount'], $p['currency'] ) ); ?><?php if ( '' !== (string) $p['invoice_number'] ) : ?><br /><a class="fw-mbr-muted" href="<?php echo esc_url( FW_Membership_Invoices::url( $p ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p['invoice_number'] ); ?></a><?php endif; ?><?php if ( '' !== (string) $p['coupon'] ) : ?><br /><code class="fw-mbr-muted"><?php echo esc_html( $p['coupon'] ); ?></code><?php endif; ?></td>
							<td><span class="fw-mbr-mstatus fw-mbr-mstatus--<?php echo esc_attr( $pill[ $p['status'] ] ?? 'expired' ); ?>"><?php echo esc_html( $labels[ $p['status'] ] ?? $p['status'] ); ?></span></td>
							<td><?php echo $g ? esc_html( $g->title() ) : esc_html( $p['gateway'] ); ?><?php echo '' !== (string) $p['gateway_ref'] ? '<br /><code class="fw-mbr-muted">' . esc_html( $p['gateway_ref'] ) . '</code>' : ''; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / 30 );
			if ( $pages > 1 ) {
				echo '<div class="tablenav bottom"><div class="tablenav-pages">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
		<?php endif; ?>
		<?php
	}

	private static function render_settings() {
		$s     = FW_Membership_Gateways::settings();
		$pages = get_pages( array( 'post_status' => array( 'publish', 'private', 'draft' ) ) );
		?>
		<p><a href="<?php echo esc_url( FW_Membership_Admin_Page::get_page_url( 'payments' ) ); ?>">← <?php esc_html_e( 'All payments', 'fw' ); ?></a></p>
		<form method="post" class="fw-mbr-pay-settings">
			<?php FW_Membership_Admin_Page::form_fields( 'save_payment_settings' ); ?>
			<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-pgen">
				<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-pgen"><?php esc_html_e( 'Checkout', 'fw' ); ?></h2></header>
				<div class="fw-mbr-fields">
					<p class="fw-mbr-field">
						<label for="fw-mbr-pcur"><?php esc_html_e( 'Default currency', 'fw' ); ?></label>
						<input type="text" id="fw-mbr-pcur" name="currency" maxlength="3" value="<?php echo esc_attr( $s['currency'] ); ?>" />
						<span class="description"><?php esc_html_e( 'Three letters, such as USD, EUR or GBP. Each price can use its own.', 'fw' ); ?></span>
					</p>
					<p class="fw-mbr-field">
						<label for="fw-mbr-pcheckout"><?php esc_html_e( 'Checkout page', 'fw' ); ?></label>
						<select id="fw-mbr-pcheckout" name="checkout_page">
							<option value="0"><?php esc_html_e( '— Choose the page with the Membership Checkout element —', 'fw' ); ?></option>
							<?php foreach ( $pages as $pg ) : ?>
								<option value="<?php echo (int) $pg->ID; ?>" <?php selected( (int) $s['checkout_page'], (int) $pg->ID ); ?>><?php echo esc_html( '' !== $pg->post_title ? $pg->post_title : '#' . $pg->ID ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="fw-mbr-field fw-mbr-field--wide">
						<label for="fw-mbr-pterms"><?php esc_html_e( 'Terms page (optional)', 'fw' ); ?></label>
						<input type="url" id="fw-mbr-pterms" name="terms_url" value="<?php echo esc_attr( $s['terms_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/terms/' ) ); ?>" />
						<span class="description"><?php esc_html_e( 'When set, buyers must tick that they accept your terms.', 'fw' ); ?></span>
					</p>
					<p class="fw-mbr-field fw-mbr-field--wide">
						<label for="fw-mbr-pror"><?php esc_html_e( 'When a member upgrades', 'fw' ); ?></label>
						<select id="fw-mbr-pror" name="proration">
							<option value="prorate" <?php selected( $s['proration'], 'prorate' ); ?>><?php esc_html_e( 'Charge the difference for the rest of the period now', 'fw' ); ?></option>
							<option value="next" <?php selected( $s['proration'], 'next' ); ?>><?php esc_html_e( 'Charge the new price from the next renewal', 'fw' ); ?></option>
						</select>
						<span class="description"><?php esc_html_e( 'Either way the new plan opens at once. Moves to a cheaper plan always happen at the next renewal, so nobody loses time they paid for.', 'fw' ); ?></span>
					</p>
				</div>
			</section>
			<?php $inv = FW_Membership_Invoices::settings(); ?>
			<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-inv">
				<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-inv"><?php esc_html_e( 'Invoices', 'fw' ); ?></h2></header>
				<div class="fw-mbr-fields">
					<p class="fw-mbr-field">
						<label for="fw-mbr-inv-business"><?php esc_html_e( 'Business name', 'fw' ); ?></label>
						<input type="text" id="fw-mbr-inv-business" name="invoice[business]" value="<?php echo esc_attr( $inv['business'] ); ?>" />
					</p>
					<p class="fw-mbr-field">
						<label for="fw-mbr-inv-prefix"><?php esc_html_e( 'Number prefix', 'fw' ); ?></label>
						<input type="text" id="fw-mbr-inv-prefix" name="invoice[prefix]" class="code" value="<?php echo esc_attr( $inv['prefix'] ); ?>" />
						<span class="description"><?php echo esc_html( sprintf( __( 'Each paid payment gets the next number: %s', 'fw' ), $inv['prefix'] . '00001' ) ); ?></span>
					</p>
					<p class="fw-mbr-field">
						<label for="fw-mbr-inv-address"><?php esc_html_e( 'Address', 'fw' ); ?></label>
						<textarea id="fw-mbr-inv-address" name="invoice[address]" rows="3"><?php echo esc_textarea( $inv['address'] ); ?></textarea>
					</p>
					<p class="fw-mbr-field">
						<label for="fw-mbr-inv-tax"><?php esc_html_e( 'Tax or VAT number', 'fw' ); ?></label>
						<input type="text" id="fw-mbr-inv-tax" name="invoice[tax_id]" value="<?php echo esc_attr( $inv['tax_id'] ); ?>" />
					</p>
					<p class="fw-mbr-field fw-mbr-field--wide">
						<label for="fw-mbr-inv-footer"><?php esc_html_e( 'Note at the bottom', 'fw' ); ?></label>
						<textarea id="fw-mbr-inv-footer" name="invoice[footer]" rows="2" placeholder="<?php esc_attr_e( 'Thank you for your support.', 'fw' ); ?>"><?php echo esc_textarea( $inv['footer'] ); ?></textarea>
						<span class="description"><?php esc_html_e( 'Members open their invoices from their account page and the receipt email, and can save them as PDF.', 'fw' ); ?></span>
					</p>
				</div>
			</section>
			<?php
			/** More payment settings panels (the Tax module adds its own). */
			do_action( 'unysonplus_membership_payment_settings_panels', $s );
			?>
			<?php foreach ( FW_Membership_Gateways::all() as $id => $g ) : ?>
				<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-g-<?php echo esc_attr( $id ); ?>">
					<header class="fw-mbr-panel__head">
						<h2 id="fw-mbr-h-g-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $g->title() ); ?></h2>
						<?php if ( $g->is_enabled() ) : ?>
							<span class="fw-mbr-mstatus fw-mbr-mstatus--<?php echo $g->is_ready() ? 'active' : 'pending'; ?>"><?php echo $g->is_ready() ? esc_html__( 'Ready', 'fw' ) : esc_html__( 'Needs settings', 'fw' ); ?></span>
						<?php endif; ?>
					</header>
					<label class="fw-mbr-check-line"><input type="checkbox" name="gateways[<?php echo esc_attr( $id ); ?>][enabled]" value="1" <?php checked( $g->is_enabled() ); ?> /> <?php esc_html_e( 'Offer this way to pay', 'fw' ); ?></label>
					<div class="fw-mbr-fields fw-mbr-fields--stack" style="margin-top:12px">
						<?php foreach ( $g->settings_fields() as $key => $f ) : ?>
							<?php
							$fid  = 'fw-mbr-g-' . $id . '-' . $key;
							$name = 'gateways[' . $id . '][' . $key . ']';
							$val  = (string) $g->setting( $key );
							?>
							<p class="fw-mbr-field">
								<label for="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( $f['label'] ); ?></label>
								<?php if ( 'textarea' === $f['type'] ) : ?>
									<textarea id="<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="4"><?php echo esc_textarea( $val ); ?></textarea>
								<?php elseif ( 'select' === $f['type'] ) : ?>
									<select id="<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $name ); ?>">
										<?php foreach ( $f['choices'] as $k => $l ) : ?>
											<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $val, $k ); ?>><?php echo esc_html( $l ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php elseif ( 'password' === $f['type'] ) : ?>
									<input type="password" id="<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $name ); ?>" value="" autocomplete="new-password" placeholder="<?php echo '' !== $val ? esc_attr( sprintf( __( 'Saved (…%s). Leave empty to keep it.', 'fw' ), substr( $val, -4 ) ) ) : ''; ?>" />
								<?php else : ?>
									<input type="text" id="<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $val ); ?>" />
								<?php endif; ?>
								<?php if ( ! empty( $f['desc'] ) ) : ?>
									<span class="description"><?php echo esc_html( $f['desc'] ); ?></span>
								<?php endif; ?>
							</p>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endforeach; ?>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save payment settings', 'fw' ); ?></button></p>
		</form>
		<?php
	}
}
