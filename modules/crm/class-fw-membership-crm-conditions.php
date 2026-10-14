<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM conditions in access rules: "who can see it" can also be people with a CRM tag,
 * on a CRM list, or in a CRM segment (rule who.extra.crm = { tags, lists, segments }).
 * Like every other choice in a rule, passing any one is enough.
 *
 * A "View as" preview has no real person behind it, so CRM conditions never pass in a
 * preview (fail closed): a visitor holds no tags, and "has none of" would otherwise be
 * true for the preview persona.
 */
class FW_Membership_CRM_Conditions {

	public static function init() {
		add_filter( 'unysonplus_membership_rule_passes', array( __CLASS__, '_passes' ), 10, 3 );
		add_action( 'unysonplus_membership_rule_who_fields', array( __CLASS__, '_fields' ) );
		add_filter( 'unysonplus_membership_rule_who_extra', array( __CLASS__, '_save' ), 10, 2 );
	}

	/**
	 * Does a person pass a rule's CRM conditions?
	 *
	 * @param array $crm     { tags, lists, segments }
	 * @param array $persona
	 *
	 * @return bool
	 */
	public static function passes( array $crm, array $persona ) {
		if ( ! empty( $persona['preview'] ) || empty( $persona['user_id'] ) || ! FW_Membership_CRM::available() ) {
			return false;
		}
		$uid = (int) $persona['user_id'];
		if ( ! empty( $crm['tags'] ) && FW_CRM_Contacts::user_has( $uid, array_values( $crm['tags'] ), 'any', 'tag' ) ) {
			return true;
		}
		if ( ! empty( $crm['lists'] ) && FW_CRM_Contacts::user_has( $uid, array_values( $crm['lists'] ), 'any', 'list' ) ) {
			return true;
		}
		if ( ! empty( $crm['segments'] ) && class_exists( 'FW_CRM_Segments' ) ) {
			$cid = (int) FW_CRM_Contacts::id_for_user( $uid );
			foreach ( (array) $crm['segments'] as $sid ) {
				if ( $cid && FW_CRM_Segments::contact_matches( (int) $sid, $cid ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** @internal */
	public static function _passes( $pass, $rule, $persona ) {
		if ( $pass || empty( $rule['who']['extra']['crm'] ) ) {
			return $pass;
		}
		return self::passes( (array) $rule['who']['extra']['crm'], $persona );
	}

	/** @internal */
	public static function _save( $extra, $r ) {
		$crm = array(
			'tags'     => array_values( array_filter( array_map( 'sanitize_key', (array) ( $r['who_crm_tags'] ?? array() ) ) ) ),
			'lists'    => array_values( array_filter( array_map( 'sanitize_key', (array) ( $r['who_crm_lists'] ?? array() ) ) ) ),
			'segments' => array_values( array_filter( array_map( 'intval', (array) ( $r['who_crm_segments'] ?? array() ) ) ) ),
		);
		if ( array_filter( $crm ) ) {
			$extra['crm'] = $crm;
		}
		return $extra;
	}

	/** @internal */
	public static function _fields( $rule ) {
		$crm      = (array) ( $rule['who']['extra']['crm'] ?? array() );
		$tags     = FW_CRM_Lists::all( 'tag' );
		$lists    = FW_CRM_Lists::all( 'list' );
		$segments = method_exists( 'FW_CRM_Lists', 'segments' ) ? FW_CRM_Lists::segments() : array();
		$open     = ! empty( $crm['tags'] ) || ! empty( $crm['lists'] ) || ! empty( $crm['segments'] );
		$groups   = array(
			'tags'     => array( __( 'With any of these CRM tags', 'fw' ), $tags, 'who_crm_tags', 'slug' ),
			'lists'    => array( __( 'On any of these CRM lists', 'fw' ), $lists, 'who_crm_lists', 'slug' ),
			'segments' => array( __( 'In any of these CRM segments', 'fw' ), $segments, 'who_crm_segments', 'id' ),
		);
		?>
		<details class="fw-mbr-more"<?php echo $open ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'CRM tags, lists and segments', 'fw' ); ?></summary>
			<?php foreach ( $groups as $key => $g ) : ?>
				<?php if ( ! $g[1] ) { continue; } ?>
				<fieldset class="fw-mbr-choices">
					<legend><?php echo esc_html( $g[0] ); ?></legend>
					<?php foreach ( $g[1] as $o ) : ?>
						<?php $val = 'id' === $g[3] ? (int) $o->id : (string) $o->slug; ?>
						<label><input type="checkbox" name="<?php echo esc_attr( $g[2] ); ?>[]" value="<?php echo esc_attr( (string) $val ); ?>" <?php checked( in_array( $val, (array) ( $crm[ $key ] ?? array() ), true ) ); ?> /> <?php echo esc_html( (string) $o->title ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			<?php endforeach; ?>
			<?php if ( ! $tags && ! $lists && ! $segments ) : ?>
				<p class="fw-mbr-muted"><?php esc_html_e( 'No CRM tags, lists or segments yet.', 'fw' ); ?></p>
			<?php endif; ?>
		</details>
		<?php
	}
}
