<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Membership box in the editor of every public post type: protect this one item
 * (for any plan or chosen plans), open it to everyone whatever the rules say, or
 * follow the access rules — and show which rules cover it now.
 */
class FW_Membership_Metabox {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, '_action_add' ) );
		add_action( 'save_post', array( __CLASS__, '_action_save' ), 10, 2 );
	}

	/** @internal */
	public static function _action_add() {
		if ( ! current_user_can( FW_Membership_Service::capability() ) ) {
			return;
		}
		foreach ( array_keys( FW_Membership_Admin_Rules::post_types() ) as $pt ) {
			add_meta_box( 'fw-mbr-access', __( 'Membership', 'fw' ), array( __CLASS__, 'render' ), $pt, 'side', 'default' );
		}
	}

	/**
	 * @param WP_Post $post
	 */
	public static function render( $post ) {
		$access = (string) get_post_meta( $post->ID, FW_Membership_Access::META_ACCESS, true );
		$mine   = array_map( 'intval', (array) get_post_meta( $post->ID, FW_Membership_Access::META_PLANS, true ) );
		$hide   = '1' === (string) get_post_meta( $post->ID, FW_Membership_Access::META_HIDE, true );
		$rules  = array_filter( array_column( FW_Membership_Rules::matching( FW_Membership_Access::context_for_post( $post ) ), 'rule' ) );
		wp_nonce_field( 'fw_mbr_metabox', 'fw_mbr_metabox_nonce' );
		?>
		<p>
			<label for="fw-mbr-access-mode" class="screen-reader-text"><?php esc_html_e( 'Access', 'fw' ); ?></label>
			<select id="fw-mbr-access-mode" name="fw_mbr_access" class="widefat">
				<option value="" <?php selected( $access, '' ); ?>><?php esc_html_e( 'Follow the access rules', 'fw' ); ?></option>
				<option value="protect" <?php selected( $access, 'protect' ); ?>><?php esc_html_e( 'Members only', 'fw' ); ?></option>
				<option value="open" <?php selected( $access, 'open' ); ?>><?php esc_html_e( 'Open to everyone', 'fw' ); ?></option>
			</select>
		</p>
		<fieldset class="fw-mbr-box-plans" style="margin:0 0 10px">
			<legend style="margin-bottom:4px"><?php esc_html_e( 'For "Members only": which plans (none ticked: any plan)', 'fw' ); ?></legend>
			<?php foreach ( FW_Membership_Plans::all() as $p ) : ?>
				<label style="display:block"><input type="checkbox" name="fw_mbr_plans[]" value="<?php echo (int) $p['id']; ?>" <?php checked( in_array( $p['id'], $mine, true ) ); ?> /> <?php echo esc_html( $p['name'] ); ?></label>
			<?php endforeach; ?>
		</fieldset>
		<p><label><input type="checkbox" name="fw_mbr_hide" value="1" <?php checked( $hide ); ?> /> <?php esc_html_e( 'Hide from lists and search for people without access', 'fw' ); ?></label></p>
		<p class="description">
			<?php if ( 'open' === $access ) : ?>
				<?php esc_html_e( 'Open to everyone: no access rule applies to this item.', 'fw' ); ?>
			<?php elseif ( $rules ) : ?>
				<?php esc_html_e( 'Access rules covering it now:', 'fw' ); ?>
				<?php foreach ( $rules as $r ) : ?>
					<br />· <a href="<?php echo esc_url( FW_Membership_Admin_Page_Url::get( 'rules' ) . '&rule=' . (int) $r['id'] ); ?>"><?php echo esc_html( $r['name'] ); ?></a>
				<?php endforeach; ?>
			<?php else : ?>
				<?php esc_html_e( 'No access rule covers it now.', 'fw' ); ?>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * @internal
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function _action_save( $post_id, $post ) {
		if ( ! isset( $_POST['fw_mbr_metabox_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fw_mbr_metabox_nonce'] ) ), 'fw_mbr_metabox' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( FW_Membership_Service::capability() ) ) {
			return;
		}
		$access = isset( $_POST['fw_mbr_access'] ) ? sanitize_key( wp_unslash( $_POST['fw_mbr_access'] ) ) : '';
		if ( in_array( $access, array( 'protect', 'open' ), true ) ) {
			update_post_meta( $post_id, FW_Membership_Access::META_ACCESS, $access );
		} else {
			delete_post_meta( $post_id, FW_Membership_Access::META_ACCESS );
		}
		$plans = isset( $_POST['fw_mbr_plans'] ) ? array_values( array_filter( array_map( 'intval', (array) wp_unslash( $_POST['fw_mbr_plans'] ) ) ) ) : array();
		if ( $plans ) {
			update_post_meta( $post_id, FW_Membership_Access::META_PLANS, $plans );
		} else {
			delete_post_meta( $post_id, FW_Membership_Access::META_PLANS );
		}
		if ( ! empty( $_POST['fw_mbr_hide'] ) ) {
			update_post_meta( $post_id, FW_Membership_Access::META_HIDE, '1' );
		} else {
			delete_post_meta( $post_id, FW_Membership_Access::META_HIDE );
		}
	}
}
