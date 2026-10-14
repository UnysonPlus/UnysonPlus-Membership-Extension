<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The access engine: the one place that answers "may this person see this?".
 *
 * Every gate asks it — the page itself, archives, search, feeds, the REST API,
 * page-builder elements and menu items — so a rule means the same thing everywhere.
 *
 *  - Which rules apply: FW_Membership_Rules::matching() for the content's context,
 *    plus the per-item override saved on the item (Membership box in the editor):
 *    "Open to everyone" removes every rule, "Members only" adds its own rule.
 *  - Every rule that applies is a lock: the person must pass ALL of them. A rule
 *    passes when the person holds one of its plans (inherited plans count), has one
 *    of its roles, or — when ticked — is simply logged in. A rule with nothing
 *    chosen passes for anyone holding any plan.
 *  - When access is denied, the most specific failing rule decides what they see.
 *  - Administrators, Membership managers and anyone who may edit the item always
 *    pass, unless they are previewing as someone else ("View as" in the admin bar).
 *
 * Plans can also give a WordPress role and extra capabilities while they are held
 * (user_has_cap, applied on the fly; nothing is written into roles).
 */
class FW_Membership_Access {

	/** User meta: the persona an administrator is previewing as. */
	const VIEW_AS_META = 'fw_mbr_view_as';

	/** Post meta set by the Membership box on an item. */
	const META_ACCESS = '_fw_mbr_access'; // '' follow the rules | 'protect' | 'open'
	const META_PLANS  = '_fw_mbr_plans';  // int[] for 'protect' (empty: any plan)
	const META_HIDE   = '_fw_mbr_hide';   // '1' hide from lists for people without access

	/**
	 * Capabilities a plan may never grant: they would let a paying member take over
	 * the site. Roles holding any of them cannot be chosen as a plan's role either.
	 */
	const UNSAFE_CAPS = array(
		'manage_options', 'edit_users', 'promote_users', 'create_users', 'delete_users', 'list_users',
		'activate_plugins', 'install_plugins', 'edit_plugins', 'update_plugins', 'delete_plugins',
		'edit_theme_options', 'switch_themes', 'install_themes', 'edit_themes', 'update_themes', 'delete_themes',
		'update_core', 'unfiltered_html', 'unfiltered_upload', 'edit_files', 'import', 'export',
		'manage_network', 'manage_sites', 'manage_network_users', 'manage_network_options', 'manage_network_plugins', 'manage_network_themes',
	);

	/** @var array Request cache: post_id|path => applicable rules. */
	private static $rules_cache = array();

	/** @var array|null The current persona. */
	private static $persona = null;

	/** @var bool Guard: user_has_cap must not recurse through itself. */
	private static $in_caps = false;

	/** @var array user_id => capabilities granted by their plans, for this request. */
	private static $granted = array();

	/** @var int The plans generation $granted was built for. */
	private static $granted_generation = -1;

	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, '_filter_user_has_cap' ), 10, 4 );
		add_action( 'admin_bar_menu', array( __CLASS__, '_action_admin_bar' ), 90 );
		add_action( 'admin_post_fw_mbr_view_as', array( __CLASS__, '_action_view_as' ) );

		// Any membership change resets the per-request caches.
		add_action( 'unysonplus_membership_changed', array( __CLASS__, 'flush' ) );
	}

	public static function flush() {
		self::$rules_cache = array();
		self::$persona     = null;
		self::$granted     = array(); // someone's plans changed
	}

	/* ---------------------------------------------------------------------- *
	 * Who is looking
	 * ---------------------------------------------------------------------- */

	/**
	 * The person access is decided for: the current user, or the persona an
	 * administrator is previewing as.
	 *
	 * @return array { user_id, logged_in, plans int[], roles string[], preview string }
	 */
	public static function persona() {
		if ( null !== self::$persona ) {
			return self::$persona;
		}
		$uid  = get_current_user_id();
		$view = $uid ? (string) get_user_meta( $uid, self::VIEW_AS_META, true ) : '';

		if ( '' !== $view && self::can_manage() ) {
			if ( 'visitor' === $view ) {
				return self::$persona = array( 'user_id' => 0, 'logged_in' => false, 'plans' => array(), 'roles' => array(), 'preview' => $view );
			}
			if ( 'nobody' === $view ) {
				return self::$persona = array( 'user_id' => 0, 'logged_in' => true, 'plans' => array(), 'roles' => array( get_option( 'default_role', 'subscriber' ) ), 'preview' => $view );
			}
			if ( 0 === strpos( $view, 'plan:' ) ) {
				return self::$persona = array( 'user_id' => 0, 'logged_in' => true, 'plans' => FW_Membership_Plans::lineage( (int) substr( $view, 5 ) ), 'roles' => array( get_option( 'default_role', 'subscriber' ) ), 'preview' => $view );
			}
		}

		return self::$persona = self::persona_for_user( $uid );
	}

	/**
	 * @param int $user_id
	 *
	 * @return array
	 */
	public static function persona_for_user( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : null;

		return array(
			'user_id'   => $user ? (int) $user->ID : 0,
			'logged_in' => (bool) $user,
			'plans'     => $user ? FW_Membership_Service::user_plans( $user->ID ) : array(),
			'roles'     => $user ? (array) $user->roles : array(),
			'preview'   => '',
		);
	}

	/** @return bool Whether the current (real) user manages Membership. */
	public static function can_manage() {
		return current_user_can( FW_Membership_Service::capability() );
	}

	/**
	 * Someone who sees everything: a Membership manager or an editor of the item, while
	 * not previewing as someone else.
	 *
	 * @param int $post_id 0 for content that is not one item.
	 *
	 * @return bool
	 */
	private static function bypass( $post_id = 0 ) {
		$p = self::persona();
		if ( '' !== $p['preview'] || ! is_user_logged_in() ) {
			return false;
		}
		return self::can_manage() || ( $post_id && current_user_can( 'edit_post', $post_id ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Which rules apply
	 * ---------------------------------------------------------------------- */

	/**
	 * The context of one item: its post type, ancestors and terms. An attachment is
	 * judged by the item it is attached to.
	 *
	 * @param WP_Post $post
	 *
	 * @return array
	 */
	public static function context_for_post( $post ) {
		if ( 'attachment' === $post->post_type && $post->post_parent && ( $parent = get_post( $post->post_parent ) ) ) {
			$post = $parent;
		}
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$found = get_the_terms( $post, $tax ); // object-term cache, primed for loops
			if ( $found && ! is_wp_error( $found ) ) {
				$terms = array_merge( $terms, array_map( 'intval', wp_list_pluck( $found, 'term_id' ) ) );
			}
		}
		return array(
			'post_id'   => (int) $post->ID,
			'post_type' => $post->post_type,
			'ancestors' => array_map( 'intval', get_post_ancestors( $post ) ),
			'terms'     => $terms,
		);
	}

	/**
	 * Rules that apply to an item.
	 *
	 * @param int|WP_Post $post
	 * @param string|null $path The request path when judging the requested page (URL rules), null otherwise.
	 *
	 * @return array[] [ { rule, specificity }, … ]
	 */
	public static function rules_for_post( $post, $path = null ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return array();
		}
		$key = $post->ID . '|' . (string) $path;
		if ( isset( self::$rules_cache[ $key ] ) ) {
			return self::$rules_cache[ $key ];
		}

		$owner  = ( 'attachment' === $post->post_type && $post->post_parent ) ? (int) $post->post_parent : (int) $post->ID;
		$access = (string) get_post_meta( $owner, self::META_ACCESS, true );
		if ( 'open' === $access ) {
			return self::$rules_cache[ $key ] = array();
		}

		$ctx = self::context_for_post( $post );
		if ( null !== $path ) {
			$ctx['path'] = $path;
		}
		$rules = array_values( FW_Membership_Rules::matching( $ctx ) );

		if ( 'protect' === $access ) {
			$rules[] = array(
				'rule'        => self::item_rule( $owner ),
				'specificity' => 60,
			);
		}

		return self::$rules_cache[ $key ] = $rules;
	}

	/**
	 * The rule the Membership box on an item stands for.
	 *
	 * @param int $post_id
	 *
	 * @return array
	 */
	private static function item_rule( $post_id ) {
		return array(
			'id'       => 0,
			'name'     => __( 'Members only (set on this item)', 'fw' ),
			'priority' => 0,
			'who'      => array( 'plans' => array_map( 'intval', (array) get_post_meta( $post_id, self::META_PLANS, true ) ), 'roles' => array(), 'logged_in' => false, 'extra' => array() ),
			'denied'   => array(
				'action'       => 'prompt',
				'message'      => '',
				'redirect'     => '',
				'teaser_words' => 55,
				'hide'         => '1' === (string) get_post_meta( $post_id, self::META_HIDE, true ),
			),
			'targets'  => array(),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * The answer
	 * ---------------------------------------------------------------------- */

	/**
	 * Does a person pass one rule?
	 *
	 * @param array $rule
	 * @param array $persona
	 *
	 * @return bool
	 */
	public static function satisfies( array $rule, array $persona ) {
		$who = $rule['who'];
		if ( ! $who['plans'] && ! $who['roles'] && ! $who['logged_in'] && empty( $who['extra'] ) ) {
			return (bool) $persona['plans']; // any plan
		}
		if ( $who['logged_in'] && $persona['logged_in'] ) {
			return true;
		}
		if ( $who['roles'] && array_intersect( $who['roles'], $persona['roles'] ) ) {
			return true;
		}
		if ( $who['plans'] && array_intersect( $who['plans'], $persona['plans'] ) ) {
			return true;
		}
		/** Lets a module (the CRM connection, courses) pass a rule on its own conditions. */
		return (bool) apply_filters( 'unysonplus_membership_rule_passes', false, $rule, $persona );
	}

	/**
	 * The most specific rule the person fails, or null when they may see it.
	 *
	 * @param array[] $rules   [ { rule, specificity }, … ]
	 * @param array   $persona
	 *
	 * @return array|null { rule, specificity }
	 */
	public static function failing( array $rules, array $persona ) {
		$fail = null;
		$now  = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		foreach ( $rules as $r ) {
			if ( self::satisfies( $r['rule'], $persona ) ) {
				/**
				 * When the rule opens for this person (local unix time; 0 = now). The Drip
				 * module answers it; until then the rule counts as not passed.
				 */
				$opens = (int) apply_filters( 'unysonplus_membership_rule_opens_at', 0, $r['rule'], $persona );
				if ( $opens <= $now ) {
					continue;
				}
				$r['opens_at'] = $opens;
			}
			if ( ! $fail || $r['specificity'] > $fail['specificity'] || ( $r['specificity'] === $fail['specificity'] && $r['rule']['priority'] > $fail['rule']['priority'] ) ) {
				$fail = $r;
			}
		}
		return $fail;
	}

	/**
	 * Why the current person may not see an item, or null when they may.
	 *
	 * @param int|WP_Post $post
	 * @param string|null $path
	 *
	 * @return array|null { rule, specificity }
	 */
	public static function denied_for_post( $post, $path = null ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}
		$rules = self::rules_for_post( $post, $path );
		if ( ! $rules || self::bypass( $post->ID ) ) {
			return null;
		}
		/** True when the person owns this one item (Pay per item), whatever the rules say. */
		if ( apply_filters( 'unysonplus_membership_item_unlocked', false, $post, self::persona() ) ) {
			return null;
		}
		return self::failing( $rules, self::persona() );
	}

	/**
	 * Why the current person may not see a page that is not one item (an archive, the
	 * blog, search, a URL), or null.
	 *
	 * @param array $ctx archive, terms, path
	 *
	 * @return array|null
	 */
	public static function denied_for_context( array $ctx ) {
		$rules = array_values( FW_Membership_Rules::matching( $ctx ) );
		if ( ! $rules || self::bypass() ) {
			return null;
		}
		return self::failing( $rules, self::persona() );
	}

	/**
	 * Public API: may a user see an item?
	 *
	 * @param int|WP_Post $post
	 * @param int|null    $user_id Null for the current person (honours "View as").
	 *
	 * @return bool
	 */
	public static function can( $post, $user_id = null ) {
		if ( null === $user_id ) {
			return null === self::denied_for_post( $post );
		}
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		$rules = self::rules_for_post( $post );
		if ( ! $rules || user_can( (int) $user_id, FW_Membership_Service::capability() ) || user_can( (int) $user_id, 'edit_post', $post->ID ) ) {
			return true;
		}
		$persona = self::persona_for_user( (int) $user_id );
		if ( apply_filters( 'unysonplus_membership_item_unlocked', false, $post, $persona ) ) {
			return true;
		}
		return null === self::failing( $rules, $persona );
	}

	/**
	 * Is an item protected at all (for anyone)?
	 *
	 * @param int|WP_Post $post
	 * @param string|null $path The request path, to count URL rules (the requested page only).
	 *
	 * @return bool
	 */
	public static function is_protected( $post, $path = null ) {
		return (bool) self::rules_for_post( $post, $path );
	}

	/**
	 * Visibility choices shared by elements and menu items.
	 *
	 * @param string $show  everyone|members|non_members|plans|not_plans|logged_in|logged_out
	 * @param int[]  $plans For plans / not_plans.
	 *
	 * @return bool
	 */
	public static function visible_to_current( $show, array $plans = array() ) {
		$p = self::persona();
		switch ( $show ) {
			case 'members':
				return (bool) $p['plans'] || self::bypass();
			case 'non_members':
				return ! $p['plans'];
			case 'plans':
				return (bool) array_intersect( array_map( 'intval', $plans ), $p['plans'] ) || self::bypass();
			case 'not_plans':
				return ! array_intersect( array_map( 'intval', $plans ), $p['plans'] );
			case 'logged_in':
				return $p['logged_in'];
			case 'logged_out':
				return ! $p['logged_in'];
		}
		return true;
	}

	/**
	 * @return array value => label
	 */
	public static function visibility_choices() {
		return array(
			'everyone'    => __( 'Everyone', 'fw' ),
			'members'     => __( 'Members (any plan)', 'fw' ),
			'non_members' => __( 'People without a plan', 'fw' ),
			'plans'       => __( 'Members of the plans below', 'fw' ),
			'not_plans'   => __( 'Everyone except members of the plans below', 'fw' ),
			'logged_in'   => __( 'Logged-in people', 'fw' ),
			'logged_out'  => __( 'Logged-out visitors', 'fw' ),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Roles and capabilities from plans
	 * ---------------------------------------------------------------------- */

	/**
	 * Roles a plan may give: those without any capability in UNSAFE_CAPS.
	 *
	 * @return array slug => name
	 */
	public static function safe_roles() {
		$out = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = array_keys( array_filter( (array) $role['capabilities'] ) );
			if ( ! array_intersect( $caps, self::UNSAFE_CAPS ) ) {
				$out[ $slug ] = translate_user_role( $role['name'] );
			}
		}
		return $out;
	}

	/**
	 * @internal Adds the capabilities of the user's plans (their role and extra caps).
	 *
	 * @param array   $allcaps
	 * @param array   $caps
	 * @param array   $args
	 * @param WP_User $user
	 *
	 * @return array
	 */
	public static function _filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( self::$in_caps || ! $user || ! $user->ID ) {
			return $allcaps;
		}
		if ( self::$granted_generation !== FW_Membership_Plans::$generation ) {
			self::$granted            = array(); // a plan changed: its role or caps may have too
			self::$granted_generation = FW_Membership_Plans::$generation;
		}
		$granted = &self::$granted;
		if ( ! isset( $granted[ $user->ID ] ) ) {
			self::$in_caps = true;
			$extra         = array();
			foreach ( FW_Membership_Service::user_plans( $user->ID ) as $plan_id ) {
				$plan = FW_Membership_Plans::get( $plan_id );
				if ( ! $plan ) {
					continue;
				}
				$role = (string) ( $plan['settings']['role'] ?? '' );
				if ( '' !== $role && ( $r = get_role( $role ) ) ) {
					$extra = array_merge( $extra, array_keys( array_filter( (array) $r->capabilities ) ) );
				}
				$extra = array_merge( $extra, (array) ( $plan['settings']['caps'] ?? array() ) );
			}
			self::$in_caps        = false;
			$granted[ $user->ID ] = array_diff( array_unique( array_map( 'sanitize_key', $extra ) ), self::UNSAFE_CAPS );
		}
		foreach ( $granted[ $user->ID ] as $cap ) {
			$allcaps[ $cap ] = true;
		}
		return $allcaps;
	}

	/* ---------------------------------------------------------------------- *
	 * "View as" preview
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 *
	 * @param WP_Admin_Bar $bar
	 */
	public static function _action_admin_bar( $bar ) {
		if ( is_admin() || ! is_user_logged_in() || ! self::can_manage() || ! FW_Membership_Plans::all( 'active' ) ) {
			return;
		}
		$now     = (string) get_user_meta( get_current_user_id(), self::VIEW_AS_META, true );
		$choices = array(
			''        => __( 'You (you see everything)', 'fw' ),
			'visitor' => __( 'A logged-out visitor', 'fw' ),
			'nobody'  => __( 'A logged-in user without a plan', 'fw' ),
		);
		foreach ( FW_Membership_Plans::all( 'active' ) as $plan ) {
			$choices[ 'plan:' . $plan['id'] ] = sprintf( __( 'A member of %s', 'fw' ), $plan['name'] );
		}
		$back = ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$bar->add_node( array(
			'id'    => 'fw-mbr-view-as',
			'title' => '' === $now
				? '<span class="ab-icon dashicons dashicons-visibility" style="top:2px" aria-hidden="true"></span>' . esc_html__( 'View as', 'fw' )
				: '<span class="ab-icon dashicons dashicons-visibility" style="top:2px;color:#f0b849" aria-hidden="true"></span>' . esc_html( sprintf( __( 'Viewing as: %s', 'fw' ), $choices[ $now ] ?? $now ) ),
			'href'  => FW_Membership_Admin_Page_Url::get(),
			'meta'  => array( 'title' => __( 'Preview this page as a visitor or a member of a plan', 'fw' ) ),
		) );
		foreach ( $choices as $value => $label ) {
			$bar->add_node( array(
				'id'     => 'fw-mbr-view-as-' . ( '' === $value ? 'you' : sanitize_html_class( str_replace( ':', '-', $value ) ) ),
				'parent' => 'fw-mbr-view-as',
				'title'  => ( $value === $now ? '✓ ' : '' ) . esc_html( $label ),
				'href'   => wp_nonce_url( add_query_arg( array( 'action' => 'fw_mbr_view_as', 'as' => '' === $value ? 'you' : $value, 'back' => rawurlencode( $back ) ), admin_url( 'admin-post.php' ) ), 'fw_mbr_view_as' ),
			) );
		}
	}

	/** @internal admin-post: switch the preview persona. */
	public static function _action_view_as() {
		check_admin_referer( 'fw_mbr_view_as' );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'fw' ), 403 );
		}
		$as = isset( $_GET['as'] ) ? sanitize_text_field( wp_unslash( $_GET['as'] ) ) : 'you';
		if ( 'you' === $as || ! preg_match( '/^(visitor|nobody|plan:\d+)$/', $as ) ) {
			delete_user_meta( get_current_user_id(), self::VIEW_AS_META );
		} else {
			update_user_meta( get_current_user_id(), self::VIEW_AS_META, $as );
		}
		$back = isset( $_GET['back'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['back'] ) ) ) : home_url( '/' );
		wp_safe_redirect( $back ? $back : home_url( '/' ) );
		exit;
	}
}

/**
 * The Membership app URL, available on the front end too (the admin page class only
 * loads in wp-admin).
 */
class FW_Membership_Admin_Page_Url {
	/**
	 * @param string $tab
	 *
	 * @return string
	 */
	public static function get( $tab = '' ) {
		$url = admin_url( 'admin.php?page=fw-membership' );
		return '' !== $tab ? add_query_arg( 'tab', $tab, $url ) : $url;
	}
}
