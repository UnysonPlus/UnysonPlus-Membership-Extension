<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Access rules and their targets. Repository: the only SQL for the rules and
 * rule_targets tables; no hooks.
 *
 * A rule is three questions:
 *  - WHAT it protects: rule_targets rows (include, or except). Types:
 *      site                         the entire site
 *      post_type   sub_type=pt      every item of a post type
 *      post        object_id=id     one item
 *      children    object_id=id     the child pages of a page (not the page itself)
 *      term        sub_type=tax     items in a category / tag / term, and its archive
 *                  object_id=term
 *      archive     sub_type=pt      a post type's archive page
 *      url         pattern=/path*   request paths (a trailing * matches the rest)
 *  - WHO may see it: `who` JSON { plans: int[], roles: string[], logged_in: bool }.
 *    Nothing chosen means "anyone holding any plan".
 *  - What everyone else sees: `denied` JSON { action: prompt|login|redirect|not_found,
 *    message, redirect, teaser_words, hide }.
 *
 * Targets are indexed rows, so the access engine asks "which rules touch a page with
 * this ID, these terms, this post type…" in one query instead of testing every rule.
 */
class FW_Membership_Rules {

	const TYPES   = array( 'site', 'post_type', 'post', 'children', 'term', 'archive', 'url' );
	const ACTIONS = array( 'prompt', 'login', 'redirect', 'not_found' );

	/** How specific a target type is: the most specific failing rule decides what a visitor sees. */
	const SPECIFICITY = array(
		'post'      => 50,
		'children'  => 40,
		'term'      => 30,
		'archive'   => 25,
		'post_type' => 20,
		'url'       => 15,
		'site'      => 10,
	);

	/** @var array|null id => rule (with targets), request cache */
	private static $cache = null;

	public static function flush() {
		self::$cache = null;
		if ( class_exists( 'FW_Membership_Access' ) ) {
			FW_Membership_Access::flush(); // its per-post answers were built on the old rules
		}
	}

	/**
	 * @return array[] id => rule, each with `targets`.
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		global $wpdb;
		$rules   = FW_Membership_Installer::table( 'rules' );
		$targets = FW_Membership_Installer::table( 'rule_targets' );

		self::$cache = array();
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$rules} ORDER BY priority DESC, name ASC", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL
			self::$cache[ (int) $row['id'] ] = self::shape( $row );
		}
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$targets} ORDER BY id ASC", ARRAY_A ) as $t ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$rid = (int) $t['rule_id'];
			if ( isset( self::$cache[ $rid ] ) ) {
				self::$cache[ $rid ]['targets'][] = self::shape_target( $t );
			}
		}
		return self::$cache;
	}

	/**
	 * @param int $id
	 *
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return $all[ (int) $id ] ?? null;
	}

	/**
	 * Active rules whose targets match a context, with which target types matched.
	 *
	 * @param array $ctx post_id, post_type, ancestors int[], terms int[], archive (post type), term_archive (term id), path
	 *
	 * @return array rule_id => { rule, specificity }
	 */
	public static function matching( array $ctx ) {
		global $wpdb;
		// Nothing switched on: nothing to look up (the common case on most pages of a
		// site that protects only a few things is still one query per item, below).
		static $any_active = null;
		if ( null === $any_active || null === self::$cache ) {
			$any_active = (bool) array_filter( self::all(), function ( $r ) {
				return 'active' === $r['status'];
			} );
		}
		if ( ! $any_active ) {
			return array();
		}
		$t   = FW_Membership_Installer::table( 'rule_targets' );
		$r   = FW_Membership_Installer::table( 'rules' );
		$or  = array( "t.type = 'site'" );
		$par = array();

		if ( ! empty( $ctx['post_type'] ) ) {
			$or[]  = "( t.type = 'post_type' AND t.sub_type = %s )";
			$par[] = (string) $ctx['post_type'];
		}
		if ( ! empty( $ctx['post_id'] ) ) {
			$or[]  = "( t.type = 'post' AND t.object_id = %d )";
			$par[] = (int) $ctx['post_id'];
		}
		$anc = array_filter( array_map( 'intval', (array) ( $ctx['ancestors'] ?? array() ) ) );
		if ( $anc ) {
			$or[] = "( t.type = 'children' AND t.object_id IN (" . implode( ',', $anc ) . ') )';
		}
		$terms = array_filter( array_map( 'intval', (array) ( $ctx['terms'] ?? array() ) ) );
		if ( $terms ) {
			$or[] = "( t.type = 'term' AND t.object_id IN (" . implode( ',', $terms ) . ') )';
		}
		if ( ! empty( $ctx['archive'] ) ) {
			$or[]  = "( t.type = 'archive' AND t.sub_type = %s )";
			$par[] = (string) $ctx['archive'];
		}
		$or[] = "t.type = 'url'";

		$sql  = "SELECT t.rule_id, t.mode, t.type, t.pattern FROM {$t} t INNER JOIN {$r} r ON r.id = t.rule_id WHERE r.status = 'active' AND ( " . implode( ' OR ', $or ) . ' )';
		$rows = $par ? $wpdb->get_results( $wpdb->prepare( $sql, $par ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL

		$path    = isset( $ctx['path'] ) ? (string) $ctx['path'] : null;
		$include = array();
		$except  = array();
		foreach ( (array) $rows as $row ) {
			if ( 'url' === $row->type && ( null === $path || ! self::path_matches( $row->pattern, $path ) ) ) {
				continue;
			}
			$rid = (int) $row->rule_id;
			if ( 'exclude' === $row->mode ) {
				$except[ $rid ] = true;
				continue;
			}
			$spec            = self::SPECIFICITY[ $row->type ] ?? 0;
			$include[ $rid ] = max( $include[ $rid ] ?? 0, $spec );
		}

		$out = array();
		foreach ( $include as $rid => $spec ) {
			if ( isset( $except[ $rid ] ) ) {
				continue;
			}
			$rule = self::get( $rid );
			if ( $rule ) {
				$out[ $rid ] = array( 'rule' => $rule, 'specificity' => $spec );
			}
		}
		return $out;
	}

	/**
	 * A trailing `*` matches anything after it: `/members/*` matches /members/ and every
	 * path under it (and /members itself); `/course*` matches /course, /courses/… .
	 * Without `*` the path must match exactly (a trailing slash is ignored).
	 *
	 * @param string $pattern
	 * @param string $path
	 *
	 * @return bool
	 */
	public static function path_matches( $pattern, $path ) {
		$pattern = '/' . ltrim( trim( (string) $pattern ), '/' );
		$path    = '/' . ltrim( (string) $path, '/' );
		if ( '*' === substr( $pattern, -1 ) ) {
			$prefix = substr( $pattern, 0, -1 );
			if ( 0 === strpos( $path, $prefix ) ) {
				return true;
			}
			// "/members/*" also covers "/members" itself.
			return '/' === substr( $prefix, -1 ) && untrailingslashit( $path ) === untrailingslashit( $prefix );
		}
		return untrailingslashit( $path ) === untrailingslashit( $pattern );
	}

	/**
	 * Insert or update a rule and replace its targets.
	 *
	 * @param array $data    name, status, priority, who, denied
	 * @param array $targets [ { mode, type, sub_type, object_id, pattern }, … ]
	 * @param int   $id
	 *
	 * @return int|WP_Error
	 */
	public static function save( array $data, array $targets, $id = 0 ) {
		global $wpdb;
		$id   = (int) $id;
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'fw_mbr_rule_name', __( 'An access rule needs a name.', 'fw' ) );
		}

		$clean = array();
		foreach ( $targets as $tg ) {
			$type = (string) ( $tg['type'] ?? '' );
			if ( ! in_array( $type, self::TYPES, true ) ) {
				continue;
			}
			$row = array(
				'mode'      => 'exclude' === ( $tg['mode'] ?? '' ) ? 'exclude' : 'include',
				'type'      => $type,
				'sub_type'  => sanitize_key( (string) ( $tg['sub_type'] ?? '' ) ),
				'object_id' => max( 0, (int) ( $tg['object_id'] ?? 0 ) ),
				'pattern'   => '',
			);
			if ( 'url' === $type ) {
				$row['pattern'] = substr( '/' . ltrim( sanitize_text_field( (string) ( $tg['pattern'] ?? '' ) ), '/' ), 0, 255 );
				if ( '/' === $row['pattern'] && empty( $tg['pattern'] ) ) {
					continue;
				}
			}
			if ( in_array( $type, array( 'post', 'children', 'term' ), true ) && ! $row['object_id'] ) {
				continue; // an item type with nothing picked
			}
			if ( in_array( $type, array( 'post_type', 'archive', 'term' ), true ) && '' === $row['sub_type'] ) {
				continue;
			}
			$clean[] = $row;
		}
		if ( ! array_filter( $clean, function ( $t ) {
			return 'include' === $t['mode'];
		} ) ) {
			return new WP_Error( 'fw_mbr_rule_targets', __( 'Choose at least one thing for the rule to protect.', 'fw' ) );
		}

		$who    = (array) ( $data['who'] ?? array() );
		$denied = (array) ( $data['denied'] ?? array() );
		$row    = array(
			'name'       => sanitize_text_field( $name ),
			'status'     => 'inactive' === ( $data['status'] ?? '' ) ? 'inactive' : 'active',
			'priority'   => (int) ( $data['priority'] ?? 0 ),
			'drip_days'  => max( 0, (int) ( $data['drip_days'] ?? 0 ) ),
			'drip_date'  => ! empty( $data['drip_date'] ) ? gmdate( 'Y-m-d 00:00:00', strtotime( (string) $data['drip_date'] ) ) : null,
			'who'        => wp_json_encode( array(
				'plans'     => array_values( array_filter( array_map( 'intval', (array) ( $who['plans'] ?? array() ) ) ) ),
				'roles'     => array_values( array_filter( array_map( 'sanitize_key', (array) ( $who['roles'] ?? array() ) ) ) ),
				'logged_in' => ! empty( $who['logged_in'] ),
				// Conditions a module adds (the CRM connection: tags, lists, segments).
				'extra'     => array_filter( (array) ( $who['extra'] ?? array() ) ),
			) ),
			'denied'     => wp_json_encode( array(
				'action'       => in_array( $denied['action'] ?? '', self::ACTIONS, true ) ? $denied['action'] : 'prompt',
				'message'      => wp_kses_post( (string) ( $denied['message'] ?? '' ) ),
				'redirect'     => esc_url_raw( (string) ( $denied['redirect'] ?? '' ) ),
				'teaser_words' => max( 0, min( 500, (int) ( $denied['teaser_words'] ?? 55 ) ) ),
				'hide'         => ! empty( $denied['hide'] ),
				'metered'      => ! empty( $denied['metered'] ),
			) ),
			'updated_at' => current_time( 'mysql' ),
		);

		$table = FW_Membership_Installer::table( 'rules' );
		if ( $id ) {
			$ok = $wpdb->update( $table, $row, array( 'id' => $id ) );
		} else {
			$row['created_at'] = $row['updated_at'];
			$ok                = $wpdb->insert( $table, $row );
			$id                = (int) $wpdb->insert_id;
		}
		if ( false === $ok ) {
			return new WP_Error( 'fw_mbr_db', __( 'The access rule could not be saved.', 'fw' ) );
		}

		$tt = FW_Membership_Installer::table( 'rule_targets' );
		$wpdb->delete( $tt, array( 'rule_id' => $id ) );
		foreach ( $clean as $tg ) {
			$tg['rule_id'] = $id;
			$wpdb->insert( $tt, $tg );
		}
		self::flush();

		return $id;
	}

	/**
	 * @param int $id
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( FW_Membership_Installer::table( 'rule_targets' ), array( 'rule_id' => (int) $id ) );
		$wpdb->delete( FW_Membership_Installer::table( 'rules' ), array( 'id' => (int) $id ) );
		self::flush();
	}

	/**
	 * @param int  $id
	 * @param bool $on
	 */
	public static function set_status( $id, $on ) {
		global $wpdb;
		$wpdb->update( FW_Membership_Installer::table( 'rules' ), array( 'status' => $on ? 'active' : 'inactive', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );
		self::flush();
	}

	/* ---------------------------------------------------------------------- */

	private static function shape( array $row ) {
		$row['id']       = (int) $row['id'];
		$row['priority'] = (int) $row['priority'];
		$row['drip_days'] = (int) ( $row['drip_days'] ?? 0 );
		$row['drip_date'] = isset( $row['drip_date'] ) && $row['drip_date'] ? (string) $row['drip_date'] : '';
		$who             = (array) json_decode( (string) $row['who'], true );
		$denied          = (array) json_decode( (string) $row['denied'], true );
		$row['who']      = array(
			'plans'     => array_map( 'intval', (array) ( $who['plans'] ?? array() ) ),
			'roles'     => array_map( 'strval', (array) ( $who['roles'] ?? array() ) ),
			'logged_in' => ! empty( $who['logged_in'] ),
			'extra'     => (array) ( $who['extra'] ?? array() ),
		);
		$row['denied']   = array(
			'action'       => in_array( $denied['action'] ?? '', self::ACTIONS, true ) ? $denied['action'] : 'prompt',
			'message'      => (string) ( $denied['message'] ?? '' ),
			'redirect'     => (string) ( $denied['redirect'] ?? '' ),
			'teaser_words' => isset( $denied['teaser_words'] ) ? (int) $denied['teaser_words'] : 55,
			'hide'         => ! empty( $denied['hide'] ),
			'metered'      => ! empty( $denied['metered'] ),
		);
		$row['targets']  = array();
		return $row;
	}

	private static function shape_target( array $t ) {
		$t['id']        = (int) $t['id'];
		$t['rule_id']   = (int) $t['rule_id'];
		$t['object_id'] = (int) $t['object_id'];
		return $t;
	}
}
