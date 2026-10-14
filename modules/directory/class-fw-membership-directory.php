<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The member directory: people with a membership who chose to be listed, searchable
 * and filterable, each with a profile page. Settings (Membership → Settings → Member
 * directory): option fw_ext_membership_directory.
 *
 *   view      members (people with a membership) | public (everyone)
 *   listing   opt_in (members tick "Show me in the directory") | opt_out
 *   plans     plans listed (empty = every plan)
 *
 * Each profile field decides who sees it (Profile fields → In the directory: not shown,
 * members, everyone). Name and avatar are shown for everyone listed; nothing else is
 * ever shown unless a field says so. The email address is never shown.
 */
class FW_Membership_Directory {

	const OPTION = 'fw_ext_membership_directory';
	const META   = '_fw_mbr_directory';

	public static function init() {
		add_action( 'unysonplus_membership_settings_panels', array( __CLASS__, '_panel' ) );
		add_action( 'unysonplus_membership_save_settings', array( __CLASS__, '_save' ) );
		add_action( 'unysonplus_membership_account_sections', array( __CLASS__, '_account' ), 8 );
		add_filter( 'unysonplus_membership_account_action', array( __CLASS__, '_handle' ), 10, 3 );
		add_filter( 'unysonplus_membership_account_notices', array( __CLASS__, '_notices' ) );
	}

	/** @return array */
	public static function settings() {
		return array_merge( array( 'view' => 'members', 'listing' => 'opt_in', 'plans' => array() ), (array) get_option( self::OPTION, array() ) );
	}

	/** @return bool May the current person browse the directory? */
	public static function can_view() {
		if ( 'public' === self::settings()['view'] ) {
			return true;
		}
		return (bool) FW_Membership_Access::persona()['plans'] || current_user_can( FW_Membership_Service::capability() );
	}

	/** @return bool Is this person listed? */
	public static function listed( $user_id ) {
		$s   = self::settings();
		$opt = (string) get_user_meta( (int) $user_id, self::META, true );
		if ( 'opt_in' === $s['listing'] ? '1' !== $opt : '0' === $opt ) {
			return false;
		}
		$plans = FW_Membership_Service::user_plans( (int) $user_id );
		return $plans && ( ! $s['plans'] || array_intersect( array_map( 'intval', $s['plans'] ), $plans ) );
	}

	/**
	 * The profile fields the current person may see in the directory.
	 *
	 * @return array[] key => field
	 */
	public static function visible_fields() {
		$member = (bool) FW_Membership_Access::persona()['plans'] || current_user_can( FW_Membership_Service::capability() );
		return array_filter( FW_Membership_Profile::fields(), function ( $f ) use ( $member ) {
			return 'public' === $f['directory'] || ( 'members' === $f['directory'] && $member );
		} );
	}

	/**
	 * Search the directory.
	 *
	 * @param array $args search, plan (id), field filters ( key => value ), page, per_page
	 *
	 * @return array { users: WP_User[], total: int }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$s      = self::settings();
		$m      = FW_Membership_Installer::table( 'memberships' );
		$in     = "'" . implode( "','", FW_Membership_Memberships::ACCESS_STATUSES ) . "'";
		$where  = array( "m.status IN ({$in})" );
		$params = array();
		$listed = array_map( 'intval', (array) $s['plans'] );
		if ( ! empty( $args['plan'] ) ) {
			// Anyone whose plan is, or includes, the chosen one.
			$holders = array();
			foreach ( FW_Membership_Plans::all() as $p ) {
				if ( in_array( (int) $args['plan'], FW_Membership_Plans::lineage( $p['id'] ), true ) ) {
					$holders[] = (int) $p['id'];
				}
			}
			$where[] = 'm.plan_id IN (' . implode( ',', $holders ? $holders : array( 0 ) ) . ')';
		} elseif ( $listed ) {
			$where[] = 'm.plan_id IN (' . implode( ',', $listed ) . ')';
		}
		$join = " INNER JOIN {$wpdb->users} u ON u.ID = m.user_id";
		if ( 'opt_in' === $s['listing'] ) {
			$join .= $wpdb->prepare( " INNER JOIN {$wpdb->usermeta} dm ON dm.user_id = u.ID AND dm.meta_key = %s AND dm.meta_value = '1'", self::META );
		} else {
			$join   .= $wpdb->prepare( " LEFT JOIN {$wpdb->usermeta} dm ON dm.user_id = u.ID AND dm.meta_key = %s", self::META );
			$where[] = "( dm.meta_value IS NULL OR dm.meta_value <> '0' )";
		}
		$visible = self::visible_fields();
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$keys    = array_map( function ( $k ) {
				return FW_Membership_Profile::PREFIX . $k;
			}, array_keys( $visible ) );
			$fields  = $keys ? $wpdb->prepare( ' OR EXISTS ( SELECT 1 FROM ' . $wpdb->usermeta . ' sm WHERE sm.user_id = u.ID AND sm.meta_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ') AND sm.meta_value LIKE %s )', array_merge( $keys, array( $like ) ) ) : ''; // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
			$where[] = '( u.display_name LIKE %s' . $fields . ' )';
			$params[] = $like;
		}
		foreach ( (array) ( $args['fields'] ?? array() ) as $key => $value ) {
			if ( '' === (string) $value || ! isset( $visible[ $key ] ) ) {
				continue; // only fields the viewer may see can filter (no guessing hidden values)
			}
			$where[]  = 'EXISTS ( SELECT 1 FROM ' . $wpdb->usermeta . ' fm WHERE fm.user_id = u.ID AND fm.meta_key = %s AND fm.meta_value = %s )';
			$params[] = FW_Membership_Profile::PREFIX . $key;
			$params[] = (string) $value;
		}
		$sql_where = implode( ' AND ', $where );
		$per       = max( 1, min( 100, (int) ( $args['per_page'] ?? 24 ) ) );
		$offset    = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per );
		$count_sql = "SELECT COUNT(DISTINCT u.ID) FROM {$m} m {$join} WHERE {$sql_where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$ids_sql   = "SELECT DISTINCT u.ID, u.display_name FROM {$m} m {$join} WHERE {$sql_where} ORDER BY u.display_name ASC LIMIT %d OFFSET %d";
		$ids       = $wpdb->get_col( $wpdb->prepare( $ids_sql, array_merge( $params, array( $per, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array( 'users' => array_filter( array_map( 'get_userdata', array_map( 'intval', (array) $ids ) ) ), 'total' => $total );
	}

	/**
	 * A person's directory card data, as the current viewer may see it.
	 *
	 * @param WP_User $u
	 *
	 * @return array { name, avatar, slug, fields: [ label => value ] }
	 */
	public static function card( $u ) {
		$out = array( 'name' => $u->display_name, 'avatar' => get_avatar_url( $u->ID, array( 'size' => 160 ) ), 'slug' => $u->user_nicename, 'fields' => array() );
		foreach ( self::visible_fields() as $key => $f ) {
			$v = FW_Membership_Profile::get( $u->ID, $key );
			if ( '' !== $v ) {
				$out['fields'][ $f['label'] ] = 'checkbox' === $f['type'] ? __( 'Yes', 'fw' ) : $v;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------- */

	/** @internal */
	public static function _account( $user ) {
		if ( ! FW_Membership_Service::user_plans( $user->ID ) ) {
			return;
		}
		$s  = self::settings();
		$on = self::listed( $user->ID ) || ( 'opt_in' === $s['listing'] ? '1' === get_user_meta( $user->ID, self::META, true ) : '0' !== get_user_meta( $user->ID, self::META, true ) );
		?>
		<section class="fw-mbr-account__section" aria-labelledby="fw-mbr-acc-dir">
			<h2 id="fw-mbr-acc-dir"><?php esc_html_e( 'Member directory', 'fw' ); ?></h2>
			<form method="post" class="fw-mbr-form">
				<?php wp_nonce_field( FW_Membership_Accounts::NONCE ); ?>
				<input type="hidden" name="fw_mbr_account_action" value="directory" />
				<p class="fw-mbr-form__row fw-mbr-form__row--check"><label><input type="checkbox" name="listed" value="1" <?php checked( $on ); ?> /> <?php esc_html_e( 'Show me in the member directory', 'fw' ); ?></label></p>
				<p class="fw-mbr-muted"><?php esc_html_e( 'Your name and picture are shown, plus the profile details this site shares in the directory. Your email address never is.', 'fw' ); ?></p>
				<p class="fw-mbr-form__actions"><button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class( true ) ); ?>"><?php esc_html_e( 'Save', 'fw' ); ?></button></p>
			</form>
		</section>
		<?php
	}

	/** @internal */
	public static function _handle( $code, $action, $user ) {
		if ( 'directory' !== $action ) {
			return $code;
		}
		update_user_meta( $user->ID, self::META, empty( $_POST['listed'] ) ? '0' : '1' ); // phpcs:ignore WordPress.Security.NonceVerification
		return 'directory';
	}

	/** @internal */
	public static function _notices( $n ) {
		$n['directory'] = array( 'success', __( 'Your directory choice is saved.', 'fw' ) );
		return $n;
	}

	/** @internal */
	public static function _save( $r ) {
		update_option( self::OPTION, array(
			'view'    => 'public' === ( $r['dir_view'] ?? '' ) ? 'public' : 'members',
			'listing' => 'opt_out' === ( $r['dir_listing'] ?? '' ) ? 'opt_out' : 'opt_in',
			'plans'   => array_values( array_filter( array_map( 'intval', (array) ( $r['dir_plans'] ?? array() ) ) ) ),
		), false );
	}

	/** @internal */
	public static function _panel() {
		$s = self::settings();
		?>
		<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-dir">
			<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-dir"><?php esc_html_e( 'Member directory', 'fw' ); ?></h2></header>
			<p class="fw-mbr-muted"><?php esc_html_e( 'Put the Member Directory element on a page. Choose what each profile field shows in Profile fields.', 'fw' ); ?></p>
			<div class="fw-mbr-fields">
				<p class="fw-mbr-field">
					<label for="fw-mbr-dir-view"><?php esc_html_e( 'Who can browse it', 'fw' ); ?></label>
					<select id="fw-mbr-dir-view" name="dir_view">
						<option value="members" <?php selected( $s['view'], 'members' ); ?>><?php esc_html_e( 'Members only', 'fw' ); ?></option>
						<option value="public" <?php selected( $s['view'], 'public' ); ?>><?php esc_html_e( 'Everyone', 'fw' ); ?></option>
					</select>
				</p>
				<p class="fw-mbr-field">
					<label for="fw-mbr-dir-listing"><?php esc_html_e( 'Who is listed', 'fw' ); ?></label>
					<select id="fw-mbr-dir-listing" name="dir_listing">
						<option value="opt_in" <?php selected( $s['listing'], 'opt_in' ); ?>><?php esc_html_e( 'Members who choose to be (recommended)', 'fw' ); ?></option>
						<option value="opt_out" <?php selected( $s['listing'], 'opt_out' ); ?>><?php esc_html_e( 'Every member, unless they opt out', 'fw' ); ?></option>
					</select>
				</p>
			</div>
			<fieldset class="fw-mbr-field">
				<legend><?php esc_html_e( 'Plans listed', 'fw' ); ?></legend>
				<?php foreach ( FW_Membership_Plans::all( 'active' ) as $p ) : ?>
					<?php if ( FW_Membership_Plans::is_item( $p ) ) { continue; } ?>
					<label class="fw-mbr-check"><input type="checkbox" name="dir_plans[]" value="<?php echo (int) $p['id']; ?>" <?php checked( in_array( (int) $p['id'], array_map( 'intval', $s['plans'] ), true ) ); ?> /> <?php echo esc_html( $p['name'] ); ?></label>
				<?php endforeach; ?>
				<span class="description"><?php esc_html_e( 'None ticked: members of every plan.', 'fw' ); ?></span>
			</fieldset>
		</section>
		<?php
	}
}
