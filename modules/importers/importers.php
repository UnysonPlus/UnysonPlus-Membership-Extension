<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Importers module (Membership → Modules → Importers): Membership → Import, for a CSV
 * file or for members kept as a WordPress role or a user field.
 */

require_once dirname( __FILE__ ) . '/class-fw-membership-importers.php';

add_filter( 'unysonplus_membership_nav', function ( $nav ) {
	$nav['setup']['items']['import'] = array( 'label' => __( 'Import', 'fw' ), 'icon' => 'dashicons-database-import' );
	return $nav;
} );

add_filter( 'unysonplus_membership_admin_action', function ( $back, $action ) {
	// phpcs:disable WordPress.Security.NonceVerification -- verified by the admin page before dispatch.
	if ( ! in_array( $action, array( 'import_csv_upload', 'import_csv_run', 'import_users_run' ), true ) ) {
		return $back;
	}
	$r   = wp_unslash( $_REQUEST );
	$key = 'fw_mbr_import_' . get_current_user_id();
	if ( 'import_csv_upload' === $action ) {
		$up = $_FILES['csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $up || UPLOAD_ERR_OK !== (int) $up['error'] || ! is_uploaded_file( $up['tmp_name'] ) ) {
			FW_Membership_Admin_Page::flash( 'error', __( 'Choose a CSV file.', 'fw' ) );
			return array( 'tab' => 'import' );
		}
		$data = FW_Membership_Importers::read_csv( $up['tmp_name'] );
		if ( is_wp_error( $data ) || ! $data['rows'] ) {
			FW_Membership_Admin_Page::flash( 'error', is_wp_error( $data ) ? $data->get_error_message() : __( 'The file has no rows.', 'fw' ) );
			return array( 'tab' => 'import' );
		}
		set_transient( $key, $data, HOUR_IN_SECONDS );
		return array( 'tab' => 'import', 'step' => 'map' );
	}
	if ( 'import_csv_run' === $action ) {
		$data = get_transient( $key );
		if ( ! $data ) {
			FW_Membership_Admin_Page::flash( 'error', __( 'The upload expired. Please upload the file again.', 'fw' ) );
			return array( 'tab' => 'import' );
		}
		$map  = (array) ( $r['map'] ?? array() );
		$rows = array();
		foreach ( $data['rows'] as $row ) {
			$out = array();
			foreach ( FW_Membership_Importers::COLUMNS as $col ) {
				$src         = (string) ( $map[ $col ] ?? '' );
				$out[ $col ] = '' !== $src && isset( $row[ $src ] ) ? $row[ $src ] : '';
			}
			$rows[] = $out;
		}
		$dry = ! empty( $r['dry_run'] );
		$res = FW_Membership_Importers::import_rows( $rows, array( 'dry_run' => $dry, 'create_users' => ! empty( $r['create_users'] ), 'notify' => ! empty( $r['notify'] ), 'default_plan' => (string) ( $r['default_plan'] ?? '' ) ) );
		if ( ! $dry ) {
			delete_transient( $key );
		}
		set_transient( $key . '_result', $res, HOUR_IN_SECONDS );
		FW_Membership_Admin_Page::flash( $res['errors'] ? 'warning' : 'success', sprintf( $dry ? __( 'Dry run: %1$d would be imported, %2$d new accounts, %3$d already here, %4$d problems.', 'fw' ) : __( 'Imported %1$d (%2$d new accounts); %3$d were already here; %4$d problems.', 'fw' ), $res['imported'], $res['created_users'], $res['skipped'], count( $res['errors'] ) ) );
		return array( 'tab' => 'import', 'step' => $dry ? 'map' : 'done' );
	}
	// Roles or a user field.
	$by  = 'meta' === ( $r['by'] ?? '' ) ? 'meta' : 'role';
	$dry = ! empty( $r['dry_run'] );
	$res = FW_Membership_Importers::import_from_users( $by, (array) ( $r['plan_for'] ?? array() ), (string) ( $r['meta_key'] ?? '' ), $dry );
	set_transient( $key . '_result', $res, HOUR_IN_SECONDS );
	FW_Membership_Admin_Page::flash( 'success', sprintf( $dry ? __( 'Dry run: %1$d would get a plan; %2$d already have it.', 'fw' ) : __( '%1$d members given their plan; %2$d already had it.', 'fw' ), $res['imported'], $res['skipped'] ) );
	return array( 'tab' => 'import', 'by' => $by, 'meta_key' => (string) ( $r['meta_key'] ?? '' ) );
	// phpcs:enable
}, 10, 2 );

add_action( 'unysonplus_membership_render_view', function ( $tab ) {
	if ( 'import' !== $tab ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- read-only view state.
	$step  = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
	$key   = 'fw_mbr_import_' . get_current_user_id();
	$data  = get_transient( $key );
	$res   = get_transient( $key . '_result' );
	$plans = array_filter( FW_Membership_Plans::all( 'active' ), function ( $p ) {
		return ! FW_Membership_Plans::is_item( $p );
	} );
	$plan_select = function ( $name, $selected = '' ) use ( $plans ) {
		echo '<select name="' . esc_attr( $name ) . '"><option value="">' . esc_html__( '— no plan —', 'fw' ) . '</option>';
		foreach ( $plans as $p ) {
			echo '<option value="' . (int) $p['id'] . '"' . selected( (string) $selected, (string) $p['id'], false ) . '>' . esc_html( $p['name'] ) . '</option>';
		}
		echo '</select>';
	};
	if ( $res && $res['errors'] ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Problems:', 'fw' ) . '</p><ul style="list-style:disc;margin-left:20px">';
		foreach ( array_slice( $res['errors'], 0, 30 ) as $e ) {
			echo '<li>' . esc_html( $e ) . '</li>';
		}
		echo '</ul></div>';
	}
	if ( 'map' === $step && $data ) :
		$guess = function ( $col ) use ( $data ) {
			foreach ( $data['header'] as $h ) {
				if ( $h === $col || false !== strpos( $h, $col ) ) {
					return $h;
				}
			}
			return '';
		};
		?>
		<form method="post" class="fw-mbr-panel">
			<?php FW_Membership_Admin_Page::form_fields( 'import_csv_run' ); ?>
			<header class="fw-mbr-panel__head"><h2><?php echo esc_html( sprintf( _n( 'Match the columns (%d row)', 'Match the columns (%d rows)', count( $data['rows'] ), 'fw' ), count( $data['rows'] ) ) ); ?></h2></header>
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Membership field', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Column in your file', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'First row', 'fw' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( FW_Membership_Importers::COLUMNS as $col ) : ?>
					<?php $g = $guess( $col ); ?>
					<tr>
						<td><code><?php echo esc_html( $col ); ?></code></td>
						<td><select name="map[<?php echo esc_attr( $col ); ?>]"><option value=""><?php esc_html_e( '— not in the file —', 'fw' ); ?></option><?php foreach ( $data['header'] as $h ) : ?><option value="<?php echo esc_attr( $h ); ?>" <?php selected( $g, $h ); ?>><?php echo esc_html( $h ); ?></option><?php endforeach; ?></select></td>
						<td class="fw-mbr-muted"><?php echo esc_html( '' !== $g ? (string) ( $data['rows'][0][ $g ] ?? '' ) : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="fw-mbr-fields">
				<p class="fw-mbr-field"><label><?php esc_html_e( 'Plan for rows without one', 'fw' ); ?></label><?php $plan_select( 'default_plan' ); ?></p>
			</div>
			<label class="fw-mbr-check-line"><input type="checkbox" name="create_users" value="1" checked /> <?php esc_html_e( 'Make accounts for emails that have none', 'fw' ); ?></label>
			<label class="fw-mbr-check-line"><input type="checkbox" name="notify" value="1" /> <?php esc_html_e( 'Email new accounts a link to set their password', 'fw' ); ?></label>
			<label class="fw-mbr-check-line"><input type="checkbox" name="dry_run" value="1" checked /> <?php esc_html_e( 'Dry run first (report only, change nothing)', 'fw' ); ?></label>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'fw' ); ?></button></p>
		</form>
		<?php
		return;
	endif;
	// phpcs:enable
	?>
	<form method="post" enctype="multipart/form-data" class="fw-mbr-panel">
		<?php FW_Membership_Admin_Page::form_fields( 'import_csv_upload' ); ?>
		<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'From a CSV file', 'fw' ); ?></h2></header>
		<p class="fw-mbr-muted"><?php echo esc_html( sprintf( __( 'One row per membership. Columns it understands: %s. With gateway and subscription_id, renewals keep arriving from the payment service as before.', 'fw' ), implode( ', ', FW_Membership_Importers::COLUMNS ) ) ); ?></p>
		<p><input type="file" name="csv" accept=".csv,text/csv" required /> <button type="submit" class="button button-primary"><?php esc_html_e( 'Upload and match columns', 'fw' ); ?></button></p>
	</form>

	<?php
	$by       = isset( $_GET['by'] ) && 'meta' === $_GET['by'] ? 'meta' : 'role'; // phpcs:ignore WordPress.Security.NonceVerification
	$meta_key = isset( $_GET['meta_key'] ) ? sanitize_key( wp_unslash( $_GET['meta_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	?>
	<section class="fw-mbr-panel">
		<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'From WordPress roles', 'fw' ); ?></h2></header>
		<p class="fw-mbr-muted"><?php esc_html_e( 'If your members were a role, give everyone with that role a plan.', 'fw' ); ?></p>
		<form method="post">
			<?php FW_Membership_Admin_Page::form_fields( 'import_users_run' ); ?>
			<input type="hidden" name="by" value="role" />
			<table class="widefat striped"><tbody>
				<?php foreach ( wp_roles()->get_names() as $slug => $name ) : ?>
					<?php if ( in_array( $slug, array( 'administrator', 'editor' ), true ) ) { continue; } ?>
					<?php $count = count( get_users( array( 'role' => $slug, 'fields' => 'ID', 'number' => 5000 ) ) ); ?>
					<tr><td><?php echo esc_html( translate_user_role( $name ) . ' (' . $count . ')' ); ?></td><td><?php $plan_select( 'plan_for[' . $slug . ']' ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
			<p><label><input type="checkbox" name="dry_run" value="1" checked /> <?php esc_html_e( 'Dry run', 'fw' ); ?></label> <button type="submit" class="button"><?php esc_html_e( 'Give the plans', 'fw' ); ?></button></p>
		</form>
	</section>

	<section class="fw-mbr-panel">
		<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'From a user field', 'fw' ); ?></h2></header>
		<p class="fw-mbr-muted"><?php esc_html_e( 'If a level or tier was kept in a user field (user meta), give each value a plan.', 'fw' ); ?></p>
		<form method="get" class="fw-mbr-inline">
			<input type="hidden" name="page" value="<?php echo esc_attr( FW_Membership_Admin_Page::PAGE_SLUG ); ?>" /><input type="hidden" name="tab" value="import" /><input type="hidden" name="by" value="meta" />
			<label><?php esc_html_e( 'Field (meta key)', 'fw' ); ?> <input type="text" name="meta_key" class="code" value="<?php echo esc_attr( $meta_key ); ?>" /></label>
			<button type="submit" class="button"><?php esc_html_e( 'Show its values', 'fw' ); ?></button>
		</form>
		<?php if ( 'meta' === $by && '' !== $meta_key ) : ?>
			<?php $values = FW_Membership_Importers::meta_values( $meta_key ); ?>
			<?php if ( ! $values ) : ?>
				<p class="fw-mbr-muted"><?php esc_html_e( 'No accounts have that field.', 'fw' ); ?></p>
			<?php else : ?>
				<form method="post">
					<?php FW_Membership_Admin_Page::form_fields( 'import_users_run' ); ?>
					<input type="hidden" name="by" value="meta" /><input type="hidden" name="meta_key" value="<?php echo esc_attr( $meta_key ); ?>" />
					<table class="widefat striped"><tbody>
						<?php foreach ( $values as $v ) : ?>
							<tr><td><code><?php echo esc_html( $v ); ?></code></td><td><?php $plan_select( 'plan_for[' . $v . ']' ); ?></td></tr>
						<?php endforeach; ?>
					</tbody></table>
					<p><label><input type="checkbox" name="dry_run" value="1" checked /> <?php esc_html_e( 'Dry run', 'fw' ); ?></label> <button type="submit" class="button"><?php esc_html_e( 'Give the plans', 'fw' ); ?></button></p>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
} );
