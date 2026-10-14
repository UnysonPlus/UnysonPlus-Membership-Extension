<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The CRM connection: on by itself while the CRM extension is active. Membership never
 * requires the CRM and never touches its tables; it uses the CRM's extension API only.
 *
 *   identity     one person, one contact (made or matched by email, linked to the user)
 *   sync         each plan mirrored to a managed tag, plus "member" / "former member"
 *   conditions   CRM tags, lists and segments in access rules
 *   timeline     membership events and payments on the contact's timeline, and a
 *                Membership panel on the contact page
 *   automations  membership triggers and actions for CRM automations
 *   summary      managed fields (status, plans, member since, value) and a Home card
 *
 * Settings (Membership → Settings → CRM connection): option fw_ext_membership_crm.
 */
class FW_Membership_CRM {

	const OPTION = 'fw_ext_membership_crm';
	const SOURCE = 'membership';

	public static function init() {
		add_action( 'unysonplus_membership_settings_panels', array( __CLASS__, '_panel' ) );
		add_action( 'unysonplus_membership_save_settings', array( __CLASS__, '_save' ) );
		add_filter( 'unysonplus_membership_admin_action', array( __CLASS__, '_action' ), 10, 2 );
	}

	/** @return array */
	public static function settings() {
		return array_merge( array(
			'contacts'    => 1,
			'tags'        => 1,
			'tag_prefix'  => 'member-',
			'status_tags' => 1,
			'timeline'    => 1,
			'fields'      => 1,
			'failed_task' => 0,
		), (array) get_option( self::OPTION, array() ) );
	}

	/** @return bool The CRM API is there. */
	public static function available() {
		return class_exists( 'FW_CRM_Contacts' ) && class_exists( 'FW_CRM_Tags' ) && class_exists( 'FW_CRM_Lists' );
	}

	/** @internal */
	public static function _save( $r ) {
		$was = self::settings();
		$out = array(
			'contacts'    => empty( $r['crm_contacts'] ) ? 0 : 1,
			'tags'        => empty( $r['crm_tags'] ) ? 0 : 1,
			'tag_prefix'  => sanitize_key( (string) ( $r['crm_tag_prefix'] ?? 'member-' ) ),
			'status_tags' => empty( $r['crm_status_tags'] ) ? 0 : 1,
			'timeline'    => empty( $r['crm_timeline'] ) ? 0 : 1,
			'fields'      => empty( $r['crm_fields'] ) ? 0 : 1,
			'failed_task' => empty( $r['crm_failed_task'] ) ? 0 : 1,
		);
		if ( '' === $out['tag_prefix'] ) {
			$out['tag_prefix'] = 'member-';
		}
		update_option( self::OPTION, $out, false );
		if ( $out['fields'] && ! $was['fields'] ) {
			FW_Membership_CRM_Summary::define_fields();
		}
	}

	/** @internal "Bring every member into the CRM now". */
	public static function _action( $back, $action ) {
		if ( 'crm_sync_all' !== $action ) {
			return $back;
		}
		$n = FW_Membership_CRM_Sync::sync_all();
		FW_Membership_Admin_Page::flash( 'success', sprintf( _n( '%d member brought up to date in the CRM.', '%d members brought up to date in the CRM.', $n, 'fw' ), $n ) );
		return array( 'tab' => 'settings' );
	}

	/** @internal */
	public static function _panel() {
		$s = self::settings();
		?>
		<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-crm">
			<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-crm"><?php esc_html_e( 'CRM connection', 'fw' ); ?></h2></header>
			<p class="fw-mbr-muted"><?php esc_html_e( 'The CRM is active, so members and the CRM work together. Membership only adds to contacts; it never removes a contact or a tag it did not add.', 'fw' ); ?></p>
			<label class="fw-mbr-check-line"><input type="checkbox" name="crm_contacts" value="1" <?php checked( $s['contacts'] ); ?> /> <?php esc_html_e( 'Every member is a CRM contact (matched by email, or added)', 'fw' ); ?></label>
			<label class="fw-mbr-check-line"><input type="checkbox" name="crm_tags" value="1" <?php checked( $s['tags'] ); ?> /> <?php esc_html_e( 'Tag contacts with their plans (one tag per plan, kept in step)', 'fw' ); ?></label>
			<label class="fw-mbr-check-line"><input type="checkbox" name="crm_status_tags" value="1" <?php checked( $s['status_tags'] ); ?> /> <?php esc_html_e( 'Also tag them "Member" while they have access and "Former member" after', 'fw' ); ?></label>
			<label class="fw-mbr-check-line"><input type="checkbox" name="crm_timeline" value="1" <?php checked( $s['timeline'] ); ?> /> <?php esc_html_e( 'Show joins, renewals, cancellations and payments on the contact’s timeline', 'fw' ); ?></label>
			<label class="fw-mbr-check-line"><input type="checkbox" name="crm_fields" value="1" <?php checked( $s['fields'] ); ?> /> <?php esc_html_e( 'Keep membership fields on the contact (status, plans, member since, total paid) for segments and filters', 'fw' ); ?></label>
			<label class="fw-mbr-check-line"><input type="checkbox" name="crm_failed_task" value="1" <?php checked( $s['failed_task'] ); ?> /> <?php esc_html_e( 'When a renewal payment fails, add a follow-up task for the contact’s owner', 'fw' ); ?></label>
			<div class="fw-mbr-fields">
				<p class="fw-mbr-field">
					<label for="fw-mbr-crm-prefix"><?php esc_html_e( 'Plan tags start with', 'fw' ); ?></label>
					<input type="text" id="fw-mbr-crm-prefix" name="crm_tag_prefix" class="code" value="<?php echo esc_attr( $s['tag_prefix'] ); ?>" />
					<span class="description"><?php esc_html_e( 'A Gold plan becomes the tag member-gold.', 'fw' ); ?></span>
				</p>
			</div>
			<p>
				<a class="button" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'crm_sync_all', array(), 'settings' ) ); ?>"><?php esc_html_e( 'Bring every member up to date in the CRM now', 'fw' ); ?></a>
			</p>
		</section>
		<?php
	}
}
