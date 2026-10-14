<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * "Show to" on every menu item (Appearance → Menus): everyone, members, people
 * without a plan, members of chosen plans, logged-in or logged-out people. A hidden
 * item takes its sub-items with it.
 */
class FW_Membership_Menus {

	const META_SHOW  = '_fw_mbr_show';
	const META_PLANS = '_fw_mbr_plans';

	public static function init() {
		add_action( 'wp_nav_menu_item_custom_fields', array( __CLASS__, '_action_fields' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( __CLASS__, '_action_save' ), 10, 2 );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, '_filter_objects' ), 20 );
	}

	/** @internal */
	public static function _action_fields( $item_id, $item ) {
		$show  = (string) get_post_meta( $item_id, self::META_SHOW, true );
		$plans = array_map( 'intval', (array) get_post_meta( $item_id, self::META_PLANS, true ) );
		$all   = FW_Membership_Plans::all();
		$id    = 'fw-mbr-menu-' . (int) $item_id;
		?>
		<div class="field-fw-mbr description description-wide" style="margin:8px 0">
			<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Show to (Membership)', 'fw' ); ?><br />
				<select id="<?php echo esc_attr( $id ); ?>" class="widefat" name="fw_mbr_menu_show[<?php echo (int) $item_id; ?>]">
					<?php foreach ( FW_Membership_Access::visibility_choices() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( '' === $show ? 'everyone' : $show, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php if ( $all ) : ?>
				<fieldset style="margin-top:6px">
					<legend class="screen-reader-text"><?php esc_html_e( 'Plans', 'fw' ); ?></legend>
					<?php foreach ( $all as $p ) : ?>
						<label style="margin-right:10px;white-space:nowrap">
							<input type="checkbox" name="fw_mbr_menu_plans[<?php echo (int) $item_id; ?>][]" value="<?php echo (int) $p['id']; ?>" <?php checked( in_array( $p['id'], $plans, true ) ); ?> />
							<?php echo esc_html( $p['name'] ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @internal Nonce: WordPress verifies the menu form's own nonce before this runs.
	 */
	public static function _action_save( $menu_id, $item_id ) {
		if ( ! current_user_can( 'edit_theme_options' ) || ! isset( $_POST['fw_mbr_menu_show'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$all  = (array) wp_unslash( $_POST['fw_mbr_menu_show'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$show = isset( $all[ $item_id ] ) ? sanitize_key( $all[ $item_id ] ) : 'everyone';
		if ( 'everyone' === $show || ! isset( FW_Membership_Access::visibility_choices()[ $show ] ) ) {
			delete_post_meta( $item_id, self::META_SHOW );
			delete_post_meta( $item_id, self::META_PLANS );
			return;
		}
		$plans = isset( $_POST['fw_mbr_menu_plans'][ $item_id ] ) ? array_map( 'intval', (array) wp_unslash( $_POST['fw_mbr_menu_plans'][ $item_id ] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		update_post_meta( $item_id, self::META_SHOW, $show );
		update_post_meta( $item_id, self::META_PLANS, array_values( array_filter( $plans ) ) );
	}

	/** @internal */
	public static function _filter_objects( $items ) {
		if ( is_admin() || ! $items ) {
			return $items;
		}
		$removed = array();
		$keep    = array();
		foreach ( $items as $item ) {
			$parent = (int) $item->menu_item_parent;
			if ( $parent && isset( $removed[ $parent ] ) ) {
				$removed[ (int) $item->ID ] = true;
				continue;
			}
			$show = (string) get_post_meta( $item->ID, self::META_SHOW, true );
			if ( '' !== $show && 'everyone' !== $show
				&& ! FW_Membership_Access::visible_to_current( $show, array_map( 'intval', (array) get_post_meta( $item->ID, self::META_PLANS, true ) ) ) ) {
				$removed[ (int) $item->ID ] = true;
				continue;
			}
			$keep[] = $item;
		}
		return $keep;
	}
}
