<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Plans: the list and the edit screen, and their actions.
 */
class FW_Membership_Admin_Plans {

	/**
	 * @param string $action
	 *
	 * @return array Query args to redirect to.
	 */
	public static function handle( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification -- verified by the admin page before dispatch.
		$id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;

		switch ( $action ) {
			case 'save_plan':
				$p      = wp_unslash( $_POST );
				$data   = array(
					'name'           => sanitize_text_field( (string) ( $p['name'] ?? '' ) ),
					'slug'           => sanitize_title( (string) ( $p['slug'] ?? '' ) ),
					'description'    => sanitize_textarea_field( (string) ( $p['description'] ?? '' ) ),
					'status'         => sanitize_key( (string) ( $p['status'] ?? 'active' ) ),
					'priority'       => (int) ( $p['priority'] ?? 0 ),
					'parent_id'      => (int) ( $p['parent_id'] ?? 0 ),
					'signup_limit'   => (int) ( $p['signup_limit'] ?? 0 ),
					'duration_value' => 0,
					'duration_unit'  => '',
					'fixed_end_date' => '',
					'settings'       => array(),
				);
				// While the plan is held: a WordPress role (only roles that cannot manage the
				// site) and extra capabilities (unsafe ones are dropped again on use).
				$existing = $id ? FW_Membership_Plans::get( $id ) : null;
				$settings = $existing ? (array) $existing['settings'] : array();
				$role     = sanitize_key( (string) ( $p['grant_role'] ?? '' ) );
				$settings['role'] = isset( FW_Membership_Access::safe_roles()[ $role ] ) ? $role : '';
				$settings['caps'] = array_values( array_diff( array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', (string) ( $p['grant_caps'] ?? '' ) ) ) ), FW_Membership_Access::UNSAFE_CAPS ) );
				$settings['login_redirect']  = esc_url_raw( (string) ( $p['login_redirect'] ?? '' ) );
				$settings['logout_redirect'] = esc_url_raw( (string) ( $p['logout_redirect'] ?? '' ) );
				// Plans a member of this plan may move to themselves (account page → Change plan).
				$settings['switch_to'] = array_values( array_diff( array_unique( array_filter( array_map( 'intval', (array) ( $p['switch_to'] ?? array() ) ) ) ), array( $id ) ) );
				/** Plan settings a module adds (Groups: team_seats), read from the posted form. */
				$settings = (array) apply_filters( 'unysonplus_membership_plan_settings', $settings, $p, $existing );
				$data['settings'] = $settings;
				$mode = sanitize_key( (string) ( $p['duration_mode'] ?? 'none' ) );
				if ( 'period' === $mode ) {
					$data['duration_value'] = (int) ( $p['duration_value'] ?? 0 );
					$data['duration_unit']  = sanitize_key( (string) ( $p['duration_unit'] ?? '' ) );
				} elseif ( 'date' === $mode ) {
					$data['fixed_end_date'] = sanitize_text_field( (string) ( $p['fixed_end_date'] ?? '' ) );
				}

				$prices = array();
				foreach ( (array) ( $p['prices'] ?? array() ) as $row ) {
					if ( ! is_array( $row ) || ( '' === trim( (string) ( $row['amount'] ?? '' ) ) && 'free' !== ( $row['billing'] ?? '' ) ) ) {
						continue;
					}
					$prices[] = array(
						'id'             => (int) ( $row['id'] ?? 0 ),
						'label'          => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
						'amount'         => (float) str_replace( ',', '.', (string) ( $row['amount'] ?? 0 ) ),
						'currency'       => sanitize_text_field( (string) ( $row['currency'] ?? '' ) ),
						'billing'        => sanitize_key( (string) ( $row['billing'] ?? 'once' ) ),
						'interval_count' => (int) ( $row['interval_count'] ?? 1 ),
						'trial_days'     => (int) ( $row['trial_days'] ?? 0 ),
						'setup_fee'      => (float) str_replace( ',', '.', (string) ( $row['setup_fee'] ?? 0 ) ),
					);
				}

				$result = FW_Membership_Plans::save( $data, $prices, $id );
				if ( is_wp_error( $result ) ) {
					FW_Membership_Admin_Page::flash( 'error', $result->get_error_message() );
					return $id ? array( 'tab' => 'plans', 'plan' => $id ) : array( 'tab' => 'plans', 'new' => 1 );
				}
				FW_Membership_Admin_Page::flash( 'success', $id ? __( 'Plan saved.', 'fw' ) : __( 'Plan created.', 'fw' ) );
				return array( 'tab' => 'plans', 'plan' => $result );

			case 'delete_plan':
				$result = FW_Membership_Plans::delete( $id );
				if ( is_wp_error( $result ) ) {
					FW_Membership_Plans::archive( $id );
					FW_Membership_Admin_Page::flash( 'warning', $result->get_error_message() );
				} else {
					FW_Membership_Admin_Page::flash( 'success', __( 'Plan deleted.', 'fw' ) );
				}
				return array( 'tab' => 'plans' );

			case 'archive_plan':
				FW_Membership_Plans::archive( $id );
				FW_Membership_Admin_Page::flash( 'success', __( 'Plan archived. Its members keep their access; nobody new can join it.', 'fw' ) );
				return array( 'tab' => 'plans' );

			case 'restore_plan':
				$plan = FW_Membership_Plans::get( $id );
				if ( $plan ) {
					$plan['status'] = 'active';
					FW_Membership_Plans::save( $plan, $plan['prices'], $id );
					FW_Membership_Admin_Page::flash( 'success', __( 'Plan restored.', 'fw' ) );
				}
				return array( 'tab' => 'plans' );
		}
		// phpcs:enable

		return array( 'tab' => 'plans' );
	}

	public static function render_list() {
		$plans  = FW_Membership_Plans::all();
		$counts = FW_Membership_Memberships::counts_by_plan();
		$base   = FW_Membership_Admin_Page::get_page_url( 'plans' );

		if ( ! $plans ) {
			FW_Membership_App::empty_state(
				'dashicons-awards',
				__( 'Create your first plan', 'fw' ),
				__( 'A plan is what someone gets when they join: Free, Supporter, Gold. Give it prices if you will sell it, a length if it should end, and later choose which content it opens.', 'fw' ),
				array( array( __( 'New plan', 'fw' ), add_query_arg( 'new', 1, $base ), true ) )
			);
			return;
		}
		?>
		<div class="fw-mbr-listbar">
			<p class="fw-mbr-muted"><?php echo esc_html( sprintf( _n( '%s plan', '%s plans', count( $plans ), 'fw' ), number_format_i18n( count( $plans ) ) ) ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'new', 1, $base ) ); ?>"><?php esc_html_e( 'New plan', 'fw' ); ?></a>
		</div>
		<table class="wp-list-table widefat fixed striped fw-mbr-plans-table">
			<thead>
				<tr>
					<th scope="col" class="column-primary"><?php esc_html_e( 'Plan', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Prices', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Length', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Includes', 'fw' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Members', 'fw' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $plans as $plan ) : ?>
					<?php
					$edit   = add_query_arg( 'plan', $plan['id'], $base );
					$parent = $plan['parent_id'] ? FW_Membership_Plans::get( $plan['parent_id'] ) : null;
					?>
					<tr>
						<td class="column-primary" data-colname="<?php esc_attr_e( 'Plan', 'fw' ); ?>">
							<strong><a class="row-title" href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $plan['name'] ); ?></a></strong>
							<?php if ( 'archived' === $plan['status'] ) : ?>
								<span class="fw-mbr-mstatus fw-mbr-mstatus--expired"><?php esc_html_e( 'Archived', 'fw' ); ?></span>
							<?php endif; ?>
							<?php if ( FW_Membership_Plans::is_item( $plan ) ) : ?>
								<span class="fw-mbr-mstatus fw-mbr-mstatus--pending"><?php echo esc_html( 'file' === $plan['settings']['item_type'] ? __( 'Download sold on its own', 'fw' ) : __( 'Page sold on its own', 'fw' ) ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== (string) $plan['description'] ) : ?>
								<br /><span class="fw-mbr-muted"><?php echo esc_html( wp_trim_words( $plan['description'], 16 ) ); ?></span>
							<?php endif; ?>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'fw' ); ?></a> | </span>
								<span><a href="<?php echo esc_url( FW_Membership_Admin_Page::get_page_url( 'members', array( 'plan_id' => $plan['id'] ) ) ); ?>"><?php esc_html_e( 'Members', 'fw' ); ?></a> | </span>
								<?php if ( 'archived' === $plan['status'] ) : ?>
									<span><a href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'restore_plan', array( 'id' => $plan['id'] ), 'plans' ) ); ?>"><?php esc_html_e( 'Restore', 'fw' ); ?></a> | </span>
								<?php else : ?>
									<span><a href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'archive_plan', array( 'id' => $plan['id'] ), 'plans' ) ); ?>"><?php esc_html_e( 'Archive', 'fw' ); ?></a> | </span>
								<?php endif; ?>
								<span class="trash"><a href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'delete_plan', array( 'id' => $plan['id'] ), 'plans' ) ); ?>"
								   onclick="return confirm('<?php echo esc_js( __( 'Delete this plan? A plan with members is archived instead.', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete', 'fw' ); ?></a></span>
							</div>
						</td>
						<td data-colname="<?php esc_attr_e( 'Prices', 'fw' ); ?>">
							<?php if ( $plan['prices'] ) : ?>
								<?php echo esc_html( implode( ' · ', array_map( array( 'FW_Membership_Plans', 'price_label' ), $plan['prices'] ) ) ); ?>
							<?php else : ?>
								<span class="fw-mbr-muted"><?php esc_html_e( 'Free', 'fw' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-colname="<?php esc_attr_e( 'Length', 'fw' ); ?>"><?php echo esc_html( FW_Membership_Plans::duration_label( $plan ) ); ?></td>
						<td data-colname="<?php esc_attr_e( 'Includes', 'fw' ); ?>"><?php echo $parent ? esc_html( $parent['name'] ) : '<span aria-hidden="true">—</span>'; ?></td>
						<td class="num" data-colname="<?php esc_attr_e( 'Members', 'fw' ); ?>">
							<a href="<?php echo esc_url( FW_Membership_Admin_Page::get_page_url( 'members', array( 'plan_id' => $plan['id'] ) ) ); ?>"><?php echo esc_html( number_format_i18n( $counts[ $plan['id'] ] ?? 0 ) ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param int $id 0 for a new plan.
	 */
	public static function render_edit( $id ) {
		$plan = $id ? FW_Membership_Plans::get( $id ) : null;
		if ( $id && ! $plan ) {
			FW_Membership_App::empty_state( 'dashicons-warning', __( 'Plan not found', 'fw' ), __( 'It may have been deleted.', 'fw' ), array( array( __( 'All plans', 'fw' ), FW_Membership_Admin_Page::get_page_url( 'plans' ) ) ) );
			return;
		}
		$plan = $plan ? $plan : array(
			'id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'status' => 'active', 'priority' => 0,
			'parent_id' => 0, 'duration_value' => 0, 'duration_unit' => '', 'fixed_end_date' => null, 'signup_limit' => 0, 'prices' => array(),
		);
		$mode     = $plan['fixed_end_date'] ? 'date' : ( $plan['duration_value'] ? 'period' : 'none' );
		$currency = (string) apply_filters( 'unysonplus_membership_default_currency', get_option( 'woocommerce_currency', 'USD' ) );
		$prices   = $plan['prices'] ? $plan['prices'] : array();
		$others   = array_filter( FW_Membership_Plans::all(), function ( $p ) use ( $plan ) {
			return $p['id'] !== $plan['id'];
		} );
		$units = array(
			'day'   => __( 'days', 'fw' ),
			'week'  => __( 'weeks', 'fw' ),
			'month' => __( 'months', 'fw' ),
			'year'  => __( 'years', 'fw' ),
		);
		$billing = array(
			'free'     => __( 'Free', 'fw' ),
			'once'     => __( 'One payment', 'fw' ),
			'lifetime' => __( 'Lifetime (one payment, never ends)', 'fw' ),
			'day'      => __( 'Every day', 'fw' ),
			'week'     => __( 'Every week', 'fw' ),
			'month'    => __( 'Every month', 'fw' ),
			'year'     => __( 'Every year', 'fw' ),
		);
		?>
		<form method="post" class="fw-mbr-plan-form" action="<?php echo esc_url( FW_Membership_Admin_Page::get_page_url( 'plans' ) ); ?>">
			<?php FW_Membership_Admin_Page::form_fields( 'save_plan' ); ?>
			<input type="hidden" name="id" value="<?php echo (int) $plan['id']; ?>" />

			<div class="fw-mbr-record">
				<div class="fw-mbr-record__main">
					<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-plan">
						<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-plan"><?php esc_html_e( 'Plan', 'fw' ); ?></h2></header>
						<div class="fw-mbr-fields">
							<p class="fw-mbr-field">
								<label for="fw-mbr-name"><?php esc_html_e( 'Name', 'fw' ); ?></label>
								<input type="text" id="fw-mbr-name" name="name" class="regular-text" required value="<?php echo esc_attr( $plan['name'] ); ?>" placeholder="<?php esc_attr_e( 'Gold', 'fw' ); ?>" />
							</p>
							<p class="fw-mbr-field">
								<label for="fw-mbr-slug"><?php esc_html_e( 'Slug', 'fw' ); ?></label>
								<input type="text" id="fw-mbr-slug" name="slug" class="regular-text code" value="<?php echo esc_attr( $plan['slug'] ); ?>" aria-describedby="fw-mbr-slug-d" />
								<span class="description" id="fw-mbr-slug-d"><?php esc_html_e( 'Used in shortcodes and the CRM tag. Made from the name when empty.', 'fw' ); ?></span>
							</p>
							<p class="fw-mbr-field fw-mbr-field--wide">
								<label for="fw-mbr-desc"><?php esc_html_e( 'Description', 'fw' ); ?></label>
								<textarea id="fw-mbr-desc" name="description" rows="3" class="large-text"><?php echo esc_textarea( $plan['description'] ); ?></textarea>
							</p>
						</div>
					</section>

					<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-prices">
						<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-prices"><?php esc_html_e( 'Prices', 'fw' ); ?></h2></header>
						<p class="fw-mbr-muted"><?php esc_html_e( 'A plan can have several prices, such as monthly and yearly. Leave it without prices for a free plan. People pay at your checkout when the Payments module is on; without it, add members by hand or through a sign-up form.', 'fw' ); ?></p>
						<table class="widefat fw-mbr-prices" id="fw-mbr-prices">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Label', 'fw' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Amount', 'fw' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Currency', 'fw' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Billing', 'fw' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Every', 'fw' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Trial days', 'fw' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Setup fee', 'fw' ); ?></th>
									<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'fw' ); ?></span></th>
								</tr>
							</thead>
							<tbody>
								<?php
								$rows = $prices ? $prices : array();
								$rows[] = null; // The template row for "Add a price".
								foreach ( $rows as $i => $price ) :
									$is_tpl = null === $price;
									$price  = $price ? $price : array( 'id' => 0, 'label' => '', 'amount' => '', 'currency' => $currency, 'billing' => 'month', 'interval_count' => 1, 'trial_days' => 0, 'setup_fee' => '' );
									$n      = $is_tpl ? '__i__' : $i;
									?>
									<tr class="fw-mbr-price<?php echo $is_tpl ? ' fw-mbr-price--tpl' : ''; ?>"<?php echo $is_tpl ? ' hidden' : ''; ?>>
										<td>
											<input type="hidden" name="prices[<?php echo esc_attr( $n ); ?>][id]" value="<?php echo (int) $price['id']; ?>"<?php disabled( $is_tpl ); ?> />
											<input type="text" name="prices[<?php echo esc_attr( $n ); ?>][label]" value="<?php echo esc_attr( $price['label'] ); ?>" placeholder="<?php esc_attr_e( 'Monthly', 'fw' ); ?>" aria-label="<?php esc_attr_e( 'Label', 'fw' ); ?>"<?php disabled( $is_tpl ); ?> />
										</td>
										<td><input type="number" min="0" step="0.01" name="prices[<?php echo esc_attr( $n ); ?>][amount]" value="<?php echo esc_attr( '' === $price['amount'] ? '' : number_format( (float) $price['amount'], 2, '.', '' ) ); ?>" aria-label="<?php esc_attr_e( 'Amount', 'fw' ); ?>"<?php disabled( $is_tpl ); ?> /></td>
										<td><input type="text" maxlength="3" size="4" name="prices[<?php echo esc_attr( $n ); ?>][currency]" value="<?php echo esc_attr( $price['currency'] ); ?>" aria-label="<?php esc_attr_e( 'Currency', 'fw' ); ?>"<?php disabled( $is_tpl ); ?> /></td>
										<td>
											<select name="prices[<?php echo esc_attr( $n ); ?>][billing]" aria-label="<?php esc_attr_e( 'Billing', 'fw' ); ?>"<?php disabled( $is_tpl ); ?>>
												<?php foreach ( $billing as $k => $label ) : ?>
													<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $price['billing'], $k ); ?>><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
										<td><input type="number" min="1" step="1" name="prices[<?php echo esc_attr( $n ); ?>][interval_count]" value="<?php echo (int) $price['interval_count']; ?>" aria-label="<?php esc_attr_e( 'Bill every N periods', 'fw' ); ?>"<?php disabled( $is_tpl ); ?> /></td>
										<td><input type="number" min="0" step="1" name="prices[<?php echo esc_attr( $n ); ?>][trial_days]" value="<?php echo (int) $price['trial_days']; ?>" aria-label="<?php esc_attr_e( 'Trial days', 'fw' ); ?>"<?php disabled( $is_tpl ); ?> /></td>
										<td><input type="number" min="0" step="0.01" name="prices[<?php echo esc_attr( $n ); ?>][setup_fee]" value="<?php echo esc_attr( '' === $price['setup_fee'] || ! (float) $price['setup_fee'] ? '' : number_format( (float) $price['setup_fee'], 2, '.', '' ) ); ?>" aria-label="<?php esc_attr_e( 'Setup fee', 'fw' ); ?>"<?php disabled( $is_tpl ); ?> /></td>
										<td><button type="button" class="button-link fw-mbr-price__remove" aria-label="<?php esc_attr_e( 'Remove this price', 'fw' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p><button type="button" class="button" id="fw-mbr-price-add"><?php esc_html_e( 'Add a price', 'fw' ); ?></button></p>
					</section>
				</div>

				<div class="fw-mbr-record__side">
					<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-length">
						<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-length"><?php esc_html_e( 'How long it lasts', 'fw' ); ?></h2></header>
						<fieldset class="fw-mbr-choices">
							<legend class="screen-reader-text"><?php esc_html_e( 'How long it lasts', 'fw' ); ?></legend>
							<label><input type="radio" name="duration_mode" value="none" <?php checked( $mode, 'none' ); ?> /> <?php esc_html_e( 'Until it is cancelled (or for as long as it is paid)', 'fw' ); ?></label>
							<label><input type="radio" name="duration_mode" value="period" <?php checked( $mode, 'period' ); ?> /> <?php esc_html_e( 'For a set time', 'fw' ); ?></label>
							<span class="fw-mbr-inline">
								<input type="number" min="1" name="duration_value" value="<?php echo $plan['duration_value'] ? (int) $plan['duration_value'] : 1; ?>" aria-label="<?php esc_attr_e( 'Number', 'fw' ); ?>" />
								<select name="duration_unit" aria-label="<?php esc_attr_e( 'Unit', 'fw' ); ?>">
									<?php foreach ( $units as $k => $label ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $plan['duration_unit'] ? $plan['duration_unit'] : 'year', $k ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</span>
							<label><input type="radio" name="duration_mode" value="date" <?php checked( $mode, 'date' ); ?> /> <?php esc_html_e( 'Until a date (everyone ends together)', 'fw' ); ?></label>
							<span class="fw-mbr-inline">
								<input type="date" name="fixed_end_date" value="<?php echo $plan['fixed_end_date'] ? esc_attr( substr( $plan['fixed_end_date'], 0, 10 ) ) : ''; ?>" aria-label="<?php esc_attr_e( 'End date', 'fw' ); ?>" />
							</span>
						</fieldset>
					</section>

					<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-more">
						<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-more"><?php esc_html_e( 'Options', 'fw' ); ?></h2></header>
						<div class="fw-mbr-fields fw-mbr-fields--stack">
							<p class="fw-mbr-field">
								<label for="fw-mbr-parent"><?php esc_html_e( 'Also includes', 'fw' ); ?></label>
								<select id="fw-mbr-parent" name="parent_id" aria-describedby="fw-mbr-parent-d">
									<option value="0"><?php esc_html_e( 'Nothing else', 'fw' ); ?></option>
									<?php foreach ( $others as $o ) : ?>
										<option value="<?php echo (int) $o['id']; ?>" <?php selected( $plan['parent_id'], $o['id'] ); ?>><?php echo esc_html( $o['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<span class="description" id="fw-mbr-parent-d"><?php esc_html_e( 'Members of this plan also get everything the chosen plan opens (Gold includes Silver).', 'fw' ); ?></span>
							</p>
							<p class="fw-mbr-field">
								<label for="fw-mbr-priority"><?php esc_html_e( 'Priority', 'fw' ); ?></label>
								<input type="number" id="fw-mbr-priority" name="priority" value="<?php echo (int) $plan['priority']; ?>" aria-describedby="fw-mbr-priority-d" />
								<span class="description" id="fw-mbr-priority-d"><?php esc_html_e( 'When someone holds several plans, the highest number decides their redirects and member area.', 'fw' ); ?></span>
							</p>
							<p class="fw-mbr-field">
								<label for="fw-mbr-limit"><?php esc_html_e( 'Member limit', 'fw' ); ?></label>
								<input type="number" min="0" id="fw-mbr-limit" name="signup_limit" value="<?php echo (int) $plan['signup_limit']; ?>" aria-describedby="fw-mbr-limit-d" />
								<span class="description" id="fw-mbr-limit-d"><?php esc_html_e( '0 for no limit. Members you add by hand are not counted against it.', 'fw' ); ?></span>
							</p>
							<?php
							/** More plan options (Groups: team seats). */
							do_action( 'unysonplus_membership_plan_option_fields', $plan );
							?>
							<?php if ( $others ) : ?>
								<fieldset class="fw-mbr-field" aria-describedby="fw-mbr-switch-d">
									<legend><?php esc_html_e( 'Members can switch to', 'fw' ); ?></legend>
									<?php $switch = array_map( 'intval', (array) ( $plan['settings']['switch_to'] ?? array() ) ); ?>
									<?php foreach ( $others as $o ) : ?>
										<label class="fw-mbr-check"><input type="checkbox" name="switch_to[]" value="<?php echo (int) $o['id']; ?>" <?php checked( in_array( (int) $o['id'], $switch, true ) ); ?> /> <?php echo esc_html( $o['name'] ); ?></label>
									<?php endforeach; ?>
									<span class="description" id="fw-mbr-switch-d"><?php esc_html_e( 'Offered on the member’s account page under “Change plan”. A move to a dearer plan happens at once and the difference is charged; a move to a cheaper one happens at the next renewal. Needs the Payments module for paid plans.', 'fw' ); ?></span>
								</fieldset>
							<?php endif; ?>
							<p class="fw-mbr-field">
								<label for="fw-mbr-login-redirect"><?php esc_html_e( 'After logging in, go to', 'fw' ); ?></label>
								<input type="url" id="fw-mbr-login-redirect" name="login_redirect" value="<?php echo esc_attr( (string) ( $plan['settings']['login_redirect'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'The account page', 'fw' ); ?>" />
							</p>
							<p class="fw-mbr-field">
								<label for="fw-mbr-logout-redirect"><?php esc_html_e( 'After logging out, go to', 'fw' ); ?></label>
								<input type="url" id="fw-mbr-logout-redirect" name="logout_redirect" value="<?php echo esc_attr( (string) ( $plan['settings']['logout_redirect'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Where WordPress sends them', 'fw' ); ?>" />
							</p>
							<p class="fw-mbr-field">
								<label for="fw-mbr-role"><?php esc_html_e( 'Role while a member', 'fw' ); ?></label>
								<select id="fw-mbr-role" name="grant_role" aria-describedby="fw-mbr-role-d">
									<option value=""><?php esc_html_e( 'No extra role', 'fw' ); ?></option>
									<?php foreach ( FW_Membership_Access::safe_roles() as $slug => $label ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( (string) ( $plan['settings']['role'] ?? '' ), $slug ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<span class="description" id="fw-mbr-role-d"><?php esc_html_e( 'Members also get this role’s abilities while the plan lasts. It is added on the fly and never written into their account, and roles that can manage the site are not offered.', 'fw' ); ?></span>
							</p>
							<p class="fw-mbr-field">
								<label for="fw-mbr-caps"><?php esc_html_e( 'Extra capabilities (advanced)', 'fw' ); ?></label>
								<input type="text" id="fw-mbr-caps" name="grant_caps" class="code" value="<?php echo esc_attr( implode( ', ', (array) ( $plan['settings']['caps'] ?? array() ) ) ); ?>" placeholder="read_private_posts" />
							</p>
							<p class="fw-mbr-field">
								<label for="fw-mbr-status"><?php esc_html_e( 'Status', 'fw' ); ?></label>
								<select id="fw-mbr-status" name="status">
									<option value="active" <?php selected( $plan['status'], 'active' ); ?>><?php esc_html_e( 'Open to new members', 'fw' ); ?></option>
									<option value="archived" <?php selected( $plan['status'], 'archived' ); ?>><?php esc_html_e( 'Archived (existing members only)', 'fw' ); ?></option>
								</select>
							</p>
						</div>
					</section>

					<p class="fw-mbr-actions">
						<button type="submit" class="button button-primary button-large"><?php echo $plan['id'] ? esc_html__( 'Save plan', 'fw' ) : esc_html__( 'Create plan', 'fw' ); ?></button>
						<a class="button button-large" href="<?php echo esc_url( FW_Membership_Admin_Page::get_page_url( 'plans' ) ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
					</p>
				</div>
			</div>
		</form>
		<script>
		( function () {
			var table = document.getElementById( 'fw-mbr-prices' );
			var add = document.getElementById( 'fw-mbr-price-add' );
			if ( ! table || ! add ) { return; }
			var body = table.tBodies[ 0 ];
			var tpl = body.querySelector( '.fw-mbr-price--tpl' );
			var next = body.rows.length;
			function sync() {
				var any = body.querySelectorAll( '.fw-mbr-price:not(.fw-mbr-price--tpl)' ).length;
				table.hidden = ! any;
			}
			add.addEventListener( 'click', function () {
				var row = tpl.cloneNode( true );
				row.hidden = false;
				row.classList.remove( 'fw-mbr-price--tpl' );
				row.querySelectorAll( '[name]' ).forEach( function ( el ) {
					el.name = el.name.replace( '__i__', String( next ) );
					el.disabled = false;
				} );
				next++;
				body.insertBefore( row, tpl );
				sync();
				var first = row.querySelector( 'input[type=text]' );
				if ( first ) { first.focus(); }
			} );
			body.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.fw-mbr-price__remove' );
				if ( btn ) { btn.closest( 'tr' ).remove(); sync(); add.focus(); }
			} );
			sync();
		}() );
		</script>
		<?php
	}
}
