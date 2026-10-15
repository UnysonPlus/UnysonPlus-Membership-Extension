<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Membership → Coupons: the list, add / edit, archive, and bulk single-use codes.
 */
class FW_Membership_Admin_Coupons {

	public static function init() {
		add_action( 'unysonplus_membership_render_view', array( __CLASS__, '_render' ) );
		add_filter( 'unysonplus_membership_admin_action', array( __CLASS__, '_handle' ), 10, 2 );
	}

	/** @internal */
	public static function _handle( $back, $action ) {
		// phpcs:disable WordPress.Security.NonceVerification -- verified by the admin page before dispatch.
		$r = wp_unslash( $_REQUEST );
		switch ( $action ) {
			case 'save_coupon':
				$id  = (int) ( $r['id'] ?? 0 );
				$res = FW_Membership_Coupons::save( self::posted( $r ), $id );
				if ( is_wp_error( $res ) ) {
					FW_Membership_Admin_Page::flash( 'error', $res->get_error_message() );
					return array( 'tab' => 'coupons', 'view' => 'edit', 'id' => $id );
				}
				FW_Membership_Admin_Page::flash( 'success', __( 'Coupon saved.', 'fw' ) );
				return array( 'tab' => 'coupons' );

			case 'generate_coupons':
				$codes = FW_Membership_Coupons::generate( self::posted( $r ), (int) ( $r['count'] ?? 10 ), (string) ( $r['prefix'] ?? '' ) );
				if ( is_wp_error( $codes ) ) {
					FW_Membership_Admin_Page::flash( 'error', $codes->get_error_message() );
					return array( 'tab' => 'coupons', 'view' => 'generate' );
				}
				$batch = FW_Membership_Coupons::get_by_code( $codes[0] )['batch'];
				FW_Membership_Admin_Page::flash( 'success', sprintf( _n( '%d code made.', '%d codes made.', count( $codes ), 'fw' ), count( $codes ) ) );
				return array( 'tab' => 'coupons', 'batch' => $batch );

			case 'archive_coupon':
			case 'restore_coupon':
				$c = FW_Membership_Coupons::get( (int) ( $r['id'] ?? 0 ) );
				if ( $c ) {
					FW_Membership_Coupons::save( array_merge( $c, array( 'status' => 'archive_coupon' === $action ? 'archived' : 'active' ) ), $c['id'] );
				}
				return array( 'tab' => 'coupons' );

			case 'export_coupons':
				$batch = sanitize_key( (string) ( $r['batch'] ?? '' ) );
				nocache_headers();
				header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="coupons-' . sanitize_file_name( (string) ( $r['batch'] ?? 'all' ) ) . '.csv"' );
				$out = fopen( 'php://output', 'w' );
				fputcsv( $out, array( 'code', 'discount', 'uses', 'max_uses', 'expires', 'status' ) );
				$page = 1;
				do { // every code, in pages of 500
					$rows = FW_Membership_Coupons::query( array( 'batch' => $batch, 'per_page' => 500, 'page' => $page++ ) );
					foreach ( $rows as $c ) {
						fputcsv( $out, array( $c['code'], FW_Membership_Coupons::label( $c ), $c['uses'], $c['max_uses'], (string) $c['expires_at'], $c['status'] ) );
					}
				} while ( 500 === count( $rows ) );
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				exit;
		}
		// phpcs:enable
		return $back;
	}

	private static function posted( array $r ) {
		return array(
			'code'        => (string) ( $r['code'] ?? '' ),
			'description' => (string) ( $r['description'] ?? '' ),
			'type'        => (string) ( $r['type'] ?? 'percent' ),
			'amount'      => (string) ( $r['amount'] ?? '' ),
			'currency'    => (string) ( $r['currency'] ?? '' ),
			'duration'    => (string) ( $r['duration'] ?? 'once' ),
			'months'      => (int) ( $r['months'] ?? 0 ),
			'plans'       => (array) ( $r['plans'] ?? array() ),
			'max_uses'    => (int) ( $r['max_uses'] ?? 0 ),
			'per_user'    => (int) ( $r['per_user'] ?? 1 ),
			'starts_at'   => (string) ( $r['starts_at'] ?? '' ),
			'expires_at'  => (string) ( $r['expires_at'] ?? '' ),
			'batch'       => (string) ( $r['batch'] ?? '' ),
		);
	}

	/** @internal */
	public static function _render( $tab ) {
		if ( 'coupons' !== $tab ) {
			return;
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'edit' === $view ) {
			self::render_form( FW_Membership_Coupons::get( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 ), false ); // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( 'generate' === $view ) {
			self::render_form( null, true );
		} else {
			self::render_list();
		}
	}

	private static function render_list() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active';
		$batch  = isset( $_GET['batch'] ) ? sanitize_key( wp_unslash( $_GET['batch'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		// phpcs:enable
		$base  = FW_Membership_Admin_Page::get_page_url( 'coupons' );
		$args  = array( 'status' => $batch ? '' : $status, 'batch' => $batch, 'search' => $search, 'per_page' => 50, 'page' => $paged );
		$rows  = FW_Membership_Coupons::query( $args );
		$total = FW_Membership_Coupons::count( $args );
		$plans = FW_Membership_Plans::all();
		?>
		<div class="fw-mbr-listbar">
			<form method="get" class="fw-mbr-listbar__search">
				<input type="hidden" name="page" value="<?php echo esc_attr( FW_Membership_Admin_Page::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="coupons" />
				<label class="screen-reader-text" for="fw-mbr-csearch"><?php esc_html_e( 'Search coupons', 'fw' ); ?></label>
				<input type="search" id="fw-mbr-csearch" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search codes', 'fw' ); ?>" />
			</form>
			<span>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'view', 'generate', $base ) ); ?>"><?php esc_html_e( 'Make many codes', 'fw' ); ?></a>
				<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'view', 'edit', $base ) ); ?>"><?php esc_html_e( 'Add coupon', 'fw' ); ?></a>
			</span>
		</div>
		<ul class="subsubsub fw-mbr-views">
			<li><a href="<?php echo esc_url( $base ); ?>"<?php echo ! $batch && 'active' === $status ? ' class="current"' : ''; ?>><?php esc_html_e( 'Active', 'fw' ); ?></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'status', 'archived', $base ) ); ?>"<?php echo ! $batch && 'archived' === $status ? ' class="current"' : ''; ?>><?php esc_html_e( 'Archived', 'fw' ); ?></a></li>
			<?php if ( $batch ) : ?>
				<li> | <?php echo esc_html( sprintf( __( 'Batch %s', 'fw' ), $batch ) ); ?> · <a href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'export_coupons', array( 'batch' => $batch ), 'coupons' ) ); ?>"><?php esc_html_e( 'Download as CSV', 'fw' ); ?></a></li>
			<?php endif; ?>
		</ul>
		<?php if ( ! $rows ) : ?>
			<?php FW_Membership_App::empty_state( 'dashicons-tickets-alt', __( 'No coupons yet', 'fw' ), __( 'A coupon takes a percentage or an amount off the first payment, every payment, or the first few months. Buyers type the code at the checkout, or you share a link with ?coupon=CODE.', 'fw' ) ); ?>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped fw-mbr-coupons-table">
				<thead><tr>
					<th scope="col" class="column-primary"><?php esc_html_e( 'Code', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Discount', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Plans', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Used', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Expires', 'fw' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $c ) : ?>
					<tr>
						<td class="column-primary">
							<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'edit', 'id' => $c['id'] ), $base ) ); ?>"><code><?php echo esc_html( $c['code'] ); ?></code></a>
							<?php if ( '' !== $c['description'] ) : ?><br /><span class="fw-mbr-muted"><?php echo esc_html( $c['description'] ); ?></span><?php endif; ?>
							<div class="row-actions">
								<span><a href="<?php echo esc_url( add_query_arg( array( 'view' => 'edit', 'id' => $c['id'] ), $base ) ); ?>"><?php esc_html_e( 'Edit', 'fw' ); ?></a> | </span>
								<?php if ( 'active' === $c['status'] ) : ?>
									<span class="trash"><a href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'archive_coupon', array( 'id' => $c['id'] ), 'coupons' ) ); ?>"><?php esc_html_e( 'Archive', 'fw' ); ?></a></span>
								<?php else : ?>
									<span><a href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'restore_coupon', array( 'id' => $c['id'] ), 'coupons' ) ); ?>"><?php esc_html_e( 'Restore', 'fw' ); ?></a></span>
								<?php endif; ?>
							</div>
						</td>
						<td><?php echo esc_html( FW_Membership_Coupons::label( $c ) ); ?></td>
						<td>
							<?php
							$names = array();
							foreach ( $c['plans'] as $pid ) {
								$names[] = isset( $plans[ $pid ] ) ? $plans[ $pid ]['name'] : '#' . $pid;
							}
							echo $names ? esc_html( implode( ', ', $names ) ) : esc_html__( 'Every plan', 'fw' );
							?>
						</td>
						<td><?php echo esc_html( $c['max_uses'] ? sprintf( '%d / %d', $c['uses'], $c['max_uses'] ) : (string) $c['uses'] ); ?></td>
						<td><?php echo $c['expires_at'] ? FW_Membership_App::date( $c['expires_at'] ) : '<span aria-hidden="true">—</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / 50 );
			if ( $pages > 1 ) {
				echo '<div class="tablenav bottom"><div class="tablenav-pages">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array|null $c
	 * @param bool       $bulk
	 */
	private static function render_form( $c, $bulk ) {
		$c = $c ? $c : array( 'id' => 0, 'code' => '', 'description' => '', 'type' => 'percent', 'amount' => '', 'currency' => FW_Membership_Gateways::currency(), 'duration' => 'once', 'months' => 3, 'plans' => array(), 'max_uses' => 0, 'per_user' => 1, 'starts_at' => '', 'expires_at' => '', 'uses' => 0 );
		?>
		<p><a href="<?php echo esc_url( FW_Membership_Admin_Page::get_page_url( 'coupons' ) ); ?>">← <?php esc_html_e( 'All coupons', 'fw' ); ?></a></p>
		<form method="post" class="fw-mbr-coupon-form">
			<?php FW_Membership_Admin_Page::form_fields( $bulk ? 'generate_coupons' : 'save_coupon' ); ?>
			<input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>" />
			<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-coupon">
				<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-coupon"><?php echo esc_html( $bulk ? __( 'Make many single-use codes', 'fw' ) : ( $c['id'] ? __( 'Edit coupon', 'fw' ) : __( 'New coupon', 'fw' ) ) ); ?></h2></header>
				<div class="fw-mbr-fields">
					<?php if ( $bulk ) : ?>
						<p class="fw-mbr-field">
							<label for="fw-mbr-ccount"><?php esc_html_e( 'How many', 'fw' ); ?></label>
							<input type="number" id="fw-mbr-ccount" name="count" min="1" max="1000" value="25" />
						</p>
						<p class="fw-mbr-field">
							<label for="fw-mbr-cprefix"><?php esc_html_e( 'Start each code with', 'fw' ); ?></label>
							<input type="text" id="fw-mbr-cprefix" name="prefix" class="code" placeholder="SPRING" />
							<span class="description"><?php esc_html_e( 'Each code can be used once, by one person. Download them as a CSV afterwards.', 'fw' ); ?></span>
						</p>
					<?php else : ?>
						<p class="fw-mbr-field">
							<label for="fw-mbr-ccode"><?php esc_html_e( 'Code', 'fw' ); ?></label>
							<input type="text" id="fw-mbr-ccode" name="code" class="code" required value="<?php echo esc_attr( $c['code'] ); ?>" placeholder="WELCOME20" />
							<span class="description"><?php esc_html_e( 'Letters, numbers, - and _. Buyers can type it in any case.', 'fw' ); ?></span>
						</p>
						<p class="fw-mbr-field">
							<label for="fw-mbr-cdesc"><?php esc_html_e( 'Note (only you see it)', 'fw' ); ?></label>
							<input type="text" id="fw-mbr-cdesc" name="description" value="<?php echo esc_attr( $c['description'] ); ?>" />
						</p>
					<?php endif; ?>
					<p class="fw-mbr-field">
						<label for="fw-mbr-ctype"><?php esc_html_e( 'Discount', 'fw' ); ?></label>
						<span class="fw-mbr-inline">
							<input type="number" id="fw-mbr-camount" name="amount" min="0" step="0.01" required value="<?php echo esc_attr( (string) $c['amount'] ); ?>" aria-label="<?php esc_attr_e( 'Amount', 'fw' ); ?>" style="max-width:8rem" />
							<select id="fw-mbr-ctype" name="type">
								<option value="percent" <?php selected( $c['type'], 'percent' ); ?>><?php esc_html_e( '% off', 'fw' ); ?></option>
								<option value="fixed" <?php selected( $c['type'], 'fixed' ); ?>><?php esc_html_e( 'off (amount)', 'fw' ); ?></option>
							</select>
							<input type="text" name="currency" maxlength="3" value="<?php echo esc_attr( (string) $c['currency'] ); ?>" aria-label="<?php esc_attr_e( 'Currency (for an amount off)', 'fw' ); ?>" style="max-width:5rem" />
						</span>
					</p>
					<p class="fw-mbr-field">
						<label for="fw-mbr-cdur"><?php esc_html_e( 'Applies to', 'fw' ); ?></label>
						<span class="fw-mbr-inline">
							<select id="fw-mbr-cdur" name="duration">
								<option value="once" <?php selected( $c['duration'], 'once' ); ?>><?php esc_html_e( 'The first payment', 'fw' ); ?></option>
								<option value="repeating" <?php selected( $c['duration'], 'repeating' ); ?>><?php esc_html_e( 'The first few months', 'fw' ); ?></option>
								<option value="forever" <?php selected( $c['duration'], 'forever' ); ?>><?php esc_html_e( 'Every payment', 'fw' ); ?></option>
							</select>
							<input type="number" name="months" min="1" value="<?php echo (int) ( $c['months'] ? $c['months'] : 3 ); ?>" aria-label="<?php esc_attr_e( 'Months', 'fw' ); ?>" style="max-width:5rem" />
							<span class="description"><?php esc_html_e( 'months (for "the first few months")', 'fw' ); ?></span>
						</span>
					</p>
					<fieldset class="fw-mbr-field">
						<legend><?php esc_html_e( 'Plans', 'fw' ); ?></legend>
						<?php foreach ( FW_Membership_Plans::all( 'active' ) as $p ) : ?>
							<label class="fw-mbr-check"><input type="checkbox" name="plans[]" value="<?php echo (int) $p['id']; ?>" <?php checked( in_array( (int) $p['id'], $c['plans'], true ) ); ?> /> <?php echo esc_html( $p['name'] ); ?></label>
						<?php endforeach; ?>
						<span class="description"><?php esc_html_e( 'None ticked: every plan.', 'fw' ); ?></span>
					</fieldset>
					<?php if ( ! $bulk ) : ?>
						<p class="fw-mbr-field">
							<label for="fw-mbr-cmax"><?php esc_html_e( 'Total uses', 'fw' ); ?></label>
							<input type="number" id="fw-mbr-cmax" name="max_uses" min="0" value="<?php echo (int) $c['max_uses']; ?>" />
							<span class="description"><?php echo esc_html( sprintf( __( '0 for no limit. Used %d times so far.', 'fw' ), (int) $c['uses'] ) ); ?></span>
						</p>
						<p class="fw-mbr-field">
							<label for="fw-mbr-cper"><?php esc_html_e( 'Uses per person', 'fw' ); ?></label>
							<input type="number" id="fw-mbr-cper" name="per_user" min="0" value="<?php echo (int) $c['per_user']; ?>" />
						</p>
					<?php endif; ?>
					<p class="fw-mbr-field">
						<label for="fw-mbr-cstart"><?php esc_html_e( 'Works from', 'fw' ); ?></label>
						<input type="date" id="fw-mbr-cstart" name="starts_at" value="<?php echo $c['starts_at'] ? esc_attr( substr( $c['starts_at'], 0, 10 ) ) : ''; ?>" />
					</p>
					<p class="fw-mbr-field">
						<label for="fw-mbr-cend"><?php esc_html_e( 'Works until', 'fw' ); ?></label>
						<input type="date" id="fw-mbr-cend" name="expires_at" value="<?php echo $c['expires_at'] ? esc_attr( substr( $c['expires_at'], 0, 10 ) ) : ''; ?>" />
					</p>
				</div>
			</section>
			<p><button type="submit" class="button button-primary"><?php echo esc_html( $bulk ? __( 'Make the codes', 'fw' ) : __( 'Save coupon', 'fw' ) ); ?></button></p>
		</form>
		<?php
	}
}
