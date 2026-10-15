<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Membership → Downloads: upload protected files, choose who may download them and how
 * often, and see the download log.
 */
class FW_Membership_Admin_Files {

	public static function init() {
		add_action( 'unysonplus_membership_render_view', array( __CLASS__, '_render' ) );
		add_filter( 'unysonplus_membership_admin_action', array( __CLASS__, '_handle' ), 10, 2 );
	}

	private static function who_from( array $r ) {
		return array(
			'plans'      => (array) ( $r['who_plans'] ?? array() ),
			'any_member' => ! empty( $r['who_any'] ),
			'logged_in'  => ! empty( $r['who_logged_in'] ),
		);
	}

	/** @internal */
	public static function _handle( $back, $action ) {
		// phpcs:disable WordPress.Security.NonceVerification -- verified by the admin page before dispatch.
		$r = wp_unslash( $_REQUEST );
		switch ( $action ) {
			case 'upload_file':
				$up = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( ! $up || UPLOAD_ERR_OK !== (int) $up['error'] || ! is_uploaded_file( $up['tmp_name'] ) ) {
					FW_Membership_Admin_Page::flash( 'error', __( 'Choose a file to upload (it may be larger than the server allows).', 'fw' ) );
					return array( 'tab' => 'files' );
				}
				$res = FW_Membership_Files::add( $up['tmp_name'], (string) $up['name'], array( 'title' => (string) ( $r['title'] ?? '' ), 'who' => self::who_from( $r ), 'monthly_limit' => (int) ( $r['monthly_limit'] ?? 0 ) ), true );
				FW_Membership_Admin_Page::flash_result( $res, __( 'File added. Put it on a page with the Member Download element.', 'fw' ) );
				return array( 'tab' => 'files' );

			case 'save_file':
				$fid = (int) ( $r['id'] ?? 0 );
				$who = self::who_from( $r );
				if ( class_exists( 'FW_Membership_Single_Purchase' ) ) {
					$item = FW_Membership_Single_Purchase::sell( 'file', $fid, (string) ( $r['title'] ?? '' ), (float) ( $r['sell_amount'] ?? 0 ), (int) ( $r['sell_days'] ?? 0 ) );
					$old  = FW_Membership_Plans::get_by_slug( 'item-file-' . $fid );
					$who['plans'] = array_values( array_diff( array_map( 'intval', $who['plans'] ), $old ? array( (int) $old['id'] ) : array() ) );
					if ( is_int( $item ) && $item ) {
						$who['plans'][] = $item; // its buyers may download it
					}
				}
				FW_Membership_Files::update( $fid, array( 'title' => (string) ( $r['title'] ?? '' ), 'who' => $who, 'monthly_limit' => (int) ( $r['monthly_limit'] ?? 0 ) ) );
				FW_Membership_Admin_Page::flash( 'success', __( 'File saved.', 'fw' ) );
				return array( 'tab' => 'files' );

			case 'delete_file':
				FW_Membership_Files::delete( (int) ( $r['id'] ?? 0 ) );
				FW_Membership_Admin_Page::flash( 'success', __( 'File deleted.', 'fw' ) );
				return array( 'tab' => 'files' );
		}
		// phpcs:enable
		return $back;
	}

	/** @internal */
	public static function _render( $tab ) {
		if ( 'files' !== $tab ) {
			return;
		}
		$edit  = isset( $_GET['id'] ) ? FW_Membership_Files::get( (int) $_GET['id'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		$files = FW_Membership_Files::all();
		$plans = FW_Membership_Plans::all( 'active' );
		$base  = FW_Membership_Admin_Page::get_page_url( 'files' );
		$who_fields = function ( $who ) use ( $plans ) {
			?>
			<fieldset class="fw-mbr-field fw-mbr-field--wide">
				<legend><?php esc_html_e( 'Who may download it', 'fw' ); ?></legend>
				<?php foreach ( $plans as $p ) : ?>
					<label class="fw-mbr-check"><input type="checkbox" name="who_plans[]" value="<?php echo (int) $p['id']; ?>" <?php checked( in_array( (int) $p['id'], $who['plans'], true ) ); ?> /> <?php echo esc_html( $p['name'] ); ?></label>
				<?php endforeach; ?>
				<label class="fw-mbr-check"><input type="checkbox" name="who_any" value="1" <?php checked( $who['any_member'] ); ?> /> <?php esc_html_e( 'Any member', 'fw' ); ?></label>
				<label class="fw-mbr-check"><input type="checkbox" name="who_logged_in" value="1" <?php checked( $who['logged_in'] ); ?> /> <?php esc_html_e( 'Anyone logged in', 'fw' ); ?></label>
				<span class="description"><?php esc_html_e( 'Nothing ticked: any member. A plan also counts for the plans it includes.', 'fw' ); ?></span>
			</fieldset>
			<?php
		};
		if ( $edit ) :
			?>
			<p><a href="<?php echo esc_url( $base ); ?>">← <?php esc_html_e( 'All downloads', 'fw' ); ?></a></p>
			<form method="post" class="fw-mbr-panel">
				<?php FW_Membership_Admin_Page::form_fields( 'save_file' ); ?>
				<input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>" />
				<header class="fw-mbr-panel__head"><h2><?php echo esc_html( $edit['original'] ); ?></h2></header>
				<div class="fw-mbr-fields">
					<p class="fw-mbr-field"><label for="fw-mbr-ftitle"><?php esc_html_e( 'Title', 'fw' ); ?></label><input type="text" id="fw-mbr-ftitle" name="title" value="<?php echo esc_attr( $edit['title'] ); ?>" /></p>
					<p class="fw-mbr-field"><label for="fw-mbr-flimit"><?php esc_html_e( 'Downloads per person per month', 'fw' ); ?></label><input type="number" min="0" id="fw-mbr-flimit" name="monthly_limit" value="<?php echo (int) $edit['monthly_limit']; ?>" /><span class="description"><?php esc_html_e( '0 for no limit.', 'fw' ); ?></span></p>
					<?php $who_fields( $edit['who'] ); ?>
					<?php if ( class_exists( 'FW_Membership_Single_Purchase' ) ) : ?>
						<?php $item = FW_Membership_Single_Purchase::plan_for( 'file', $edit['id'] ); ?>
						<p class="fw-mbr-field"><label for="fw-mbr-fsell"><?php esc_html_e( 'Sell on its own for', 'fw' ); ?></label><input type="number" min="0" step="0.01" id="fw-mbr-fsell" name="sell_amount" value="<?php echo $item ? esc_attr( (string) $item['prices'][0]['amount'] ) : ''; ?>" /><span class="description"><?php echo esc_html( sprintf( __( '%s. Empty: not for sale. Buyers can download it from their account and the Member Download element.', 'fw' ), FW_Membership_Gateways::currency() ) ); ?></span></p>
						<p class="fw-mbr-field"><label for="fw-mbr-fselld"><?php esc_html_e( 'Access for (days)', 'fw' ); ?></label><input type="number" min="0" id="fw-mbr-fselld" name="sell_days" value="<?php echo $item ? (int) $item['duration_value'] : 0; ?>" /><span class="description"><?php esc_html_e( '0 = for ever.', 'fw' ); ?></span></p>
					<?php endif; ?>
				</div>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'fw' ); ?></button></p>
			</form>
			<section class="fw-mbr-panel">
				<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'Latest downloads', 'fw' ); ?></h2></header>
				<?php $log = FW_Membership_Files::recent( 50, $edit['id'] ); ?>
				<?php if ( ! $log ) : ?>
					<p class="fw-mbr-muted"><?php esc_html_e( 'Not downloaded yet.', 'fw' ); ?></p>
				<?php else : ?>
					<table class="widefat striped"><tbody>
						<?php foreach ( $log as $l ) : ?>
							<?php $u = get_userdata( (int) $l['user_id'] ); ?>
							<tr><td><?php echo FW_Membership_App::date( $l['created_at'] ) . ' ' . esc_html( substr( $l['created_at'], 11, 5 ) ); ?></td><td><?php echo $u ? esc_html( $u->display_name ) : esc_html__( '(deleted)', 'fw' ); ?></td></tr>
						<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
			</section>
			<?php
			return;
		endif;
		?>
		<form method="post" enctype="multipart/form-data" class="fw-mbr-panel">
			<?php FW_Membership_Admin_Page::form_fields( 'upload_file' ); ?>
			<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'Add a protected file', 'fw' ); ?></h2></header>
			<div class="fw-mbr-fields">
				<p class="fw-mbr-field"><label for="fw-mbr-ffile"><?php esc_html_e( 'File', 'fw' ); ?></label><input type="file" id="fw-mbr-ffile" name="file" required /><span class="description"><?php echo esc_html( sprintf( __( 'Up to %s. Stored outside the public Media Library, with no address anyone can guess.', 'fw' ), size_format( wp_max_upload_size() ) ) ); ?></span></p>
				<p class="fw-mbr-field"><label for="fw-mbr-ftitle2"><?php esc_html_e( 'Title', 'fw' ); ?></label><input type="text" id="fw-mbr-ftitle2" name="title" /></p>
				<p class="fw-mbr-field"><label for="fw-mbr-flimit2"><?php esc_html_e( 'Downloads per person per month', 'fw' ); ?></label><input type="number" min="0" id="fw-mbr-flimit2" name="monthly_limit" value="0" /></p>
				<?php $who_fields( array( 'plans' => array(), 'any_member' => false, 'logged_in' => false ) ); ?>
			</div>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload', 'fw' ); ?></button></p>
		</form>
		<?php if ( ! $files ) : ?>
			<?php FW_Membership_App::empty_state( 'dashicons-download', __( 'No protected files yet', 'fw' ), __( 'Upload a file above, then place it on any page with the Member Download element. Only the people you choose can download it.', 'fw' ) ); ?>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th scope="col" class="column-primary"><?php esc_html_e( 'File', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Who', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Size', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Downloads', 'fw' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $files as $f ) : ?>
					<tr>
						<td class="column-primary"><a href="<?php echo esc_url( add_query_arg( 'id', $f['id'], $base ) ); ?>"><strong><?php echo esc_html( $f['title'] ); ?></strong></a><br /><code class="fw-mbr-muted"><?php echo esc_html( $f['original'] ); ?></code>
							<div class="row-actions"><span><a href="<?php echo esc_url( add_query_arg( 'id', $f['id'], $base ) ); ?>"><?php esc_html_e( 'Edit / log', 'fw' ); ?></a> | </span><span><a href="<?php echo esc_url( FW_Membership_Files::url( $f['id'] ) ); ?>"><?php esc_html_e( 'Download', 'fw' ); ?></a> | </span><span class="trash"><a href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'delete_file', array( 'id' => $f['id'] ), 'files' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this file? Download buttons for it stop working.', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete', 'fw' ); ?></a></span></div>
						</td>
						<td>
							<?php
							$names = array();
							foreach ( $f['who']['plans'] as $pid ) {
								$names[] = isset( $plans[ $pid ] ) ? $plans[ $pid ]['name'] : '#' . $pid;
							}
							if ( $f['who']['any_member'] || ( ! $names && ! $f['who']['logged_in'] ) ) {
								$names[] = __( 'Any member', 'fw' );
							}
							if ( $f['who']['logged_in'] ) {
								$names[] = __( 'Anyone logged in', 'fw' );
							}
							echo esc_html( implode( ', ', $names ) );
							echo $f['monthly_limit'] ? '<br /><span class="fw-mbr-muted">' . esc_html( sprintf( __( '%d a month each', 'fw' ), $f['monthly_limit'] ) ) . '</span>' : '';
							?>
						</td>
						<td><?php echo esc_html( size_format( $f['size'] ) ); ?></td>
						<td><?php echo (int) $f['downloads']; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
