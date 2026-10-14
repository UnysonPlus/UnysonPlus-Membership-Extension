<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * AI Assistant abilities for Membership: enough to SET UP a membership site (plans,
 * access rules, settings) and nothing that reads or changes the people in it.
 *
 *   membership-describe     plans (prices, length, member COUNTS), access rules, modules,
 *                           settings and pages — counts only, never a member
 *   membership-save-plan    create or update a plan, matched by slug (safe to call twice)
 *   membership-save-rule    create or update an access rule, matched by name
 *   membership-settings     member pages, sign-up and cancel options, button classes
 *                           (the settings option is snapshotted, so undo-change restores it)
 *
 * Plans and rules live in Membership's own tables, which undo-change cannot restore, so
 * those abilities say what they changed; the owner can edit or delete it in the app.
 */

if ( ! function_exists( 'fw_ext_membership_ai_register' ) ) :

	function fw_ext_membership_ai_register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) || ! class_exists( 'FW_Membership_Service' ) ) {
			return;
		}
		$cap = FW_Membership_Service::capability();

		fw_ai_register_ability( 'membership-describe', array(
			'label'       => __( 'Describe Membership', 'fw' ),
			'description' => 'The membership set-up: plans (slug, name, prices, length, which plan each includes, how many members hold it), access rules (what each protects, who may see it, what others see), switchable modules and whether each is available, and the settings (account and login pages, sign-up options). Counts only: never a member\'s name, email or history.',
			'permission'  => $cap,
			'readonly'    => true,
			'execute'     => 'fw_ext_membership_ai_describe',
		) );

		fw_ai_register_ability( 'membership-save-plan', array(
			'label'       => __( 'Create or update a membership plan', 'fw' ),
			'description' => 'Creates or updates a plan matched by slug (a plan with the same slug is updated). Fields: name (required), slug, description, prices [ { label, amount, currency (3 letters), billing: free | once | lifetime | day | week | month | year, every (n), trial_days, setup_fee } ] (an empty list = free), length { value, unit: day | week | month | year } or until (YYYY-MM-DD) for a fixed end date, or neither for "until cancelled", includes (slug of a plan whose access this one also gets), priority (higher wins), member_limit (0 = none), status: active | archived. Prices are versioned: a changed price becomes a new row and the old one is archived, so existing members keep what they agreed to. Taking payments arrives with the Payments module. Not undoable with undo_change.',
			'input'       => array(
				'name'         => array( 'type' => 'string', 'minLength' => 1 ),
				'slug'         => array( 'type' => 'string' ),
				'description'  => array( 'type' => 'string' ),
				'prices'       => array( 'type' => 'array' ),
				'length'       => array( 'type' => 'object' ),
				'until'        => array( 'type' => 'string' ),
				'includes'     => array( 'type' => 'string' ),
				'priority'     => array( 'type' => 'integer' ),
				'member_limit' => array( 'type' => 'integer' ),
				'status'       => array( 'type' => 'string' ),
			),
			'required'    => array( 'name' ),
			'permission'  => $cap,
			'idempotent'  => true,
			'execute'     => 'fw_ext_membership_ai_save_plan',
		) );

		fw_ai_register_ability( 'membership-save-rule', array(
			'label'       => __( 'Create or update an access rule', 'fw' ),
			'description' => 'Creates or updates an access rule matched by name. protects: [ { type: site | post_type | post | children | term | archive | url, except?: bool, post_type? (for post_type / archive), post? (id, slug or title, for post / children), taxonomy? + term? (slug, id or name, for term), pattern? (for url, e.g. /members/*) } ]. who: { plans?: [plan slugs] (a plan also counts for the plans it includes), roles?: [role slugs], logged_in?: bool } — nothing given means members of any plan; passing any one is enough. denied: { action: prompt (teaser + log-in / join prompt, the default) | login | redirect | not_found, message?, redirect? (address), teaser_words? (0-500, default 55), hide? (also hide from lists and search) }. Every rule covering a page must be passed. status: active | inactive. Not undoable with undo_change.',
			'input'       => array(
				'name'     => array( 'type' => 'string', 'minLength' => 1 ),
				'protects' => array( 'type' => 'array' ),
				'who'      => array( 'type' => 'object' ),
				'denied'   => array( 'type' => 'object' ),
				'priority' => array( 'type' => 'integer' ),
				'status'   => array( 'type' => 'string' ),
			),
			'required'    => array( 'name', 'protects' ),
			'permission'  => $cap,
			'idempotent'  => true,
			'execute'     => 'fw_ext_membership_ai_save_rule',
		) );

		fw_ai_register_ability( 'membership-settings', array(
			'label'       => __( 'Change Membership settings', 'fw' ),
			'description' => 'Updates Membership settings; only the keys given change. account_page / login_page: a page id, slug or title (the pages that hold the Member Account / Member Login elements; 0 = none). after_verify: address people land on after confirming their email. verify_days (1-30). notify_admin (bool) and admin_email: who hears about sign-ups waiting for approval. member_cancel (bool): members may cancel from their account page. button_class / button_class_2: the theme\'s button classes for Membership\'s buttons (e.g. "btn btn-primary"). Undoable with undo_change.',
			'input'       => array(
				'account_page'   => array(),
				'login_page'     => array(),
				'after_verify'   => array( 'type' => 'string' ),
				'verify_days'    => array( 'type' => 'integer' ),
				'notify_admin'   => array( 'type' => 'boolean' ),
				'admin_email'    => array( 'type' => 'string' ),
				'member_cancel'  => array( 'type' => 'boolean' ),
				'button_class'   => array( 'type' => 'string' ),
				'button_class_2' => array( 'type' => 'string' ),
			),
			'permission'  => $cap,
			'idempotent'  => true,
			'execute'     => 'fw_ext_membership_ai_settings',
		) );
	}
	add_action( 'fw_ai_assistant_register_abilities', 'fw_ext_membership_ai_register' );

	/** @return array */
	function fw_ext_membership_ai_describe() {
		$counts = FW_Membership_Memberships::counts_by_plan();
		$plans  = array();
		foreach ( FW_Membership_Plans::all() as $p ) {
			$out             = FW_Membership_REST::plan_out( $p );
			$parent          = $p['parent_id'] ? FW_Membership_Plans::get( $p['parent_id'] ) : null;
			$out['includes'] = $parent ? $parent['slug'] : null;
			$out['members']  = (int) ( $counts[ $p['id'] ] ?? 0 );
			$plans[]         = $out;
		}
		$rules = array();
		foreach ( FW_Membership_Rules::all() as $r ) {
			$rules[] = array(
				'name'     => $r['name'],
				'status'   => $r['status'],
				'protects' => array_map( function ( $t ) {
					return ( 'exclude' === $t['mode'] ? 'except ' : '' ) . FW_Membership_AI_Labels::target( $t );
				}, $r['targets'] ),
				'who'      => FW_Membership_AI_Labels::who( $r ),
				'denied'   => $r['denied'],
			);
		}
		$modules = array();
		foreach ( FW_Membership_Modules::registry() as $slug => $m ) {
			$modules[ $slug ] = array( 'label' => $m['label'], 'available' => FW_Membership_Modules::is_available( $slug ), 'on' => FW_Membership_Modules::is_on( $slug ), 'phase' => $m['phase'] );
		}
		$s = FW_Membership_Settings::get();
		return array(
			'plans'        => $plans,
			'rules'        => $rules,
			'modules'      => $modules,
			'settings'     => array_merge( $s, array(
				'account_page' => $s['account_page'] ? get_the_title( $s['account_page'] ) . ' (#' . $s['account_page'] . ')' : '',
				'login_page'   => $s['login_page'] ? get_the_title( $s['login_page'] ) . ' (#' . $s['login_page'] . ')' : '',
			) ),
			'members_with_access' => FW_Membership_Memberships::count_members(),
			'elements'     => 'Member Login, Member Account and Paywall Prompt elements; a Membership tab on every element; [member_content plans="slug"]…[/member_content]; sign-up = a contact form with the "Register a member" action.',
		);
	}

	/**
	 * @param array $in
	 *
	 * @return array|WP_Error
	 */
	function fw_ext_membership_ai_save_plan( $in ) {
		$in       = (array) $in;
		$slug     = sanitize_title( (string) ( $in['slug'] ?? $in['name'] ) );
		$existing = FW_Membership_Plans::get_by_slug( $slug );
		$includes = ! empty( $in['includes'] ) ? FW_Membership_Plans::get_by_slug( sanitize_title( (string) $in['includes'] ) ) : null;
		if ( ! empty( $in['includes'] ) && ! $includes ) {
			return new WP_Error( 'fw_mbr_ai_includes', sprintf( 'No plan with the slug "%s" to include.', $in['includes'] ) );
		}
		$data = array(
			'name'           => (string) $in['name'],
			'slug'           => $slug,
			'description'    => (string) ( $in['description'] ?? ( $existing['description'] ?? '' ) ),
			'status'         => (string) ( $in['status'] ?? ( $existing['status'] ?? 'active' ) ),
			'priority'       => isset( $in['priority'] ) ? (int) $in['priority'] : (int) ( $existing['priority'] ?? 0 ),
			'parent_id'      => $includes ? $includes['id'] : ( array_key_exists( 'includes', $in ) ? 0 : (int) ( $existing['parent_id'] ?? 0 ) ),
			'signup_limit'   => isset( $in['member_limit'] ) ? (int) $in['member_limit'] : (int) ( $existing['signup_limit'] ?? 0 ),
			'duration_value' => (int) ( $in['length']['value'] ?? 0 ),
			'duration_unit'  => (string) ( $in['length']['unit'] ?? '' ),
			'fixed_end_date' => (string) ( $in['until'] ?? '' ),
			'settings'       => $existing ? $existing['settings'] : array(),
		);
		$prices = array();
		$old    = $existing ? $existing['prices'] : array();
		foreach ( (array) ( $in['prices'] ?? $old ) as $i => $p ) {
			$p        = (array) $p;
			$prices[] = array(
				'id'             => (int) ( $p['id'] ?? ( $old[ $i ]['id'] ?? 0 ) ),
				'label'          => (string) ( $p['label'] ?? '' ),
				'amount'         => (float) ( $p['amount'] ?? 0 ),
				'currency'       => (string) ( $p['currency'] ?? 'USD' ),
				'billing'        => (string) ( $p['billing'] ?? 'month' ),
				'interval_count' => (int) ( $p['every'] ?? $p['interval_count'] ?? 1 ),
				'trial_days'     => (int) ( $p['trial_days'] ?? 0 ),
				'setup_fee'      => (float) ( $p['setup_fee'] ?? 0 ),
			);
		}
		$id = FW_Membership_Plans::save( $data, $prices, $existing ? $existing['id'] : 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$plan = FW_Membership_Plans::get( $id );
		return array(
			'ok'      => true,
			'message' => sprintf( '%s the plan "%s" (%s; %s). Edit or delete it under Membership > Plans.', $existing ? 'Updated' : 'Created', $plan['name'], FW_Membership_Plans::duration_label( $plan ), $plan['prices'] ? implode( ', ', array_map( array( 'FW_Membership_Plans', 'price_label' ), $plan['prices'] ) ) : 'free' ),
			'plan'    => FW_Membership_REST::plan_out( $plan ),
		);
	}

	/**
	 * @param array $in
	 *
	 * @return array|WP_Error
	 */
	function fw_ext_membership_ai_save_rule( $in ) {
		$in      = (array) $in;
		$targets = array();
		foreach ( (array) $in['protects'] as $t ) {
			$t    = (array) $t;
			$type = sanitize_key( (string) ( $t['type'] ?? '' ) );
			$row  = array( 'mode' => ! empty( $t['except'] ) ? 'exclude' : 'include', 'type' => $type, 'sub_type' => '', 'object_id' => 0, 'pattern' => '' );
			switch ( $type ) {
				case 'post_type':
				case 'archive':
					$row['sub_type'] = sanitize_key( (string) ( $t['post_type'] ?? 'post' ) );
					break;
				case 'post':
				case 'children':
					$post = FW_Membership_AI_Labels::find_post( $t['post'] ?? '', (string) ( $t['post_type'] ?? '' ) );
					if ( ! $post ) {
						return new WP_Error( 'fw_mbr_ai_post', sprintf( 'Could not find the page or post "%s".', is_scalar( $t['post'] ?? '' ) ? $t['post'] : '' ) );
					}
					$row['object_id'] = $post->ID;
					$row['sub_type']  = $post->post_type;
					break;
				case 'term':
					$tax  = sanitize_key( (string) ( $t['taxonomy'] ?? 'category' ) );
					$term = is_numeric( $t['term'] ?? '' ) ? get_term( (int) $t['term'], $tax ) : ( get_term_by( 'slug', sanitize_title( (string) ( $t['term'] ?? '' ) ), $tax ) ?: get_term_by( 'name', (string) ( $t['term'] ?? '' ), $tax ) );
					if ( ! $term || is_wp_error( $term ) ) {
						return new WP_Error( 'fw_mbr_ai_term', sprintf( 'Could not find the %s "%s".', $tax, is_scalar( $t['term'] ?? '' ) ? $t['term'] : '' ) );
					}
					$row['sub_type']  = $tax;
					$row['object_id'] = (int) $term->term_id;
					break;
				case 'url':
					$row['pattern'] = (string) ( $t['pattern'] ?? '' );
					break;
			}
			$targets[] = $row;
		}
		$plans = array();
		foreach ( (array) ( $in['who']['plans'] ?? array() ) as $ref ) {
			$p = is_numeric( $ref ) ? FW_Membership_Plans::get( (int) $ref ) : FW_Membership_Plans::get_by_slug( sanitize_title( (string) $ref ) );
			if ( ! $p ) {
				return new WP_Error( 'fw_mbr_ai_plan', sprintf( 'No plan "%s".', is_scalar( $ref ) ? $ref : '' ) );
			}
			$plans[] = $p['id'];
		}
		$existing = null;
		foreach ( FW_Membership_Rules::all() as $r ) {
			if ( 0 === strcasecmp( $r['name'], trim( (string) $in['name'] ) ) ) {
				$existing = $r;
				break;
			}
		}
		$id = FW_Membership_Rules::save( array(
			'name'     => (string) $in['name'],
			'status'   => (string) ( $in['status'] ?? 'active' ),
			'priority' => (int) ( $in['priority'] ?? 0 ),
			'who'      => array( 'plans' => $plans, 'roles' => (array) ( $in['who']['roles'] ?? array() ), 'logged_in' => ! empty( $in['who']['logged_in'] ) ),
			'denied'   => array_merge( array( 'action' => 'prompt', 'teaser_words' => 55 ), (array) ( $in['denied'] ?? array() ) ),
		), $targets, $existing ? $existing['id'] : 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$rule = FW_Membership_Rules::get( $id );
		return array(
			'ok'      => true,
			'message' => sprintf( '%s the access rule "%s": protects %s; visible to %s. It applies straight away; edit it under Membership > Access rules, and use "View as" in the admin bar to check it.', $existing ? 'Updated' : 'Created', $rule['name'], implode( '; ', array_map( array( 'FW_Membership_AI_Labels', 'target' ), $rule['targets'] ) ), FW_Membership_AI_Labels::who( $rule ) ),
			'id'      => $id,
		);
	}

	/**
	 * @param array $in
	 *
	 * @return array
	 */
	function fw_ext_membership_ai_settings( $in ) {
		$in = (array) $in;
		$s  = FW_Membership_Settings::get();
		foreach ( array( 'account_page', 'login_page' ) as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$page   = FW_Membership_AI_Labels::find_post( $in[ $k ], 'page' );
				$s[ $k ] = $page ? $page->ID : 0;
			}
		}
		foreach ( array( 'after_verify', 'verify_days', 'notify_admin', 'admin_email', 'member_cancel', 'button_class', 'button_class_2' ) as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$s[ $k ] = $in[ $k ];
			}
		}
		$rev = function_exists( 'fw_ai_snapshot' ) ? fw_ai_snapshot( array( 'options' => array( FW_Membership_Settings::OPTION ) ), 'membership-settings', 'Membership settings' ) : 0;
		FW_Membership_Settings::save( $s );
		return array( 'ok' => true, 'message' => 'Membership settings saved.', 'revision' => $rev, 'settings' => FW_Membership_Settings::get() );
	}

	/** Small helpers shared by the abilities. */
	class FW_Membership_AI_Labels {
		/** @return WP_Post|null */
		public static function find_post( $ref, $type = '' ) {
			if ( is_numeric( $ref ) ) {
				return get_post( (int) $ref );
			}
			$ref   = trim( (string) $ref );
			$types = '' !== $type ? array( $type ) : array_keys( get_post_types( array( 'public' => true ) ) );
			foreach ( $types as $pt ) {
				$p = get_page_by_path( sanitize_title( $ref ), OBJECT, $pt );
				if ( $p ) {
					return $p;
				}
			}
			$q = get_posts( array( 'post_type' => $types, 'title' => $ref, 'posts_per_page' => 1, 'post_status' => 'any', 'suppress_filters' => true ) );
			return $q ? $q[0] : null;
		}

		public static function target( array $t ) {
			switch ( $t['type'] ) {
				case 'site':
					return 'the entire site';
				case 'post_type':
					return 'all ' . $t['sub_type'];
				case 'archive':
					return 'the ' . $t['sub_type'] . ' archive';
				case 'post':
					return '"' . get_the_title( $t['object_id'] ) . '"';
				case 'children':
					return 'pages under "' . get_the_title( $t['object_id'] ) . '"';
				case 'term':
					$term = get_term( $t['object_id'] );
					return $t['sub_type'] . ' "' . ( $term && ! is_wp_error( $term ) ? $term->name : $t['object_id'] ) . '"';
				case 'url':
					return 'addresses ' . $t['pattern'];
			}
			return $t['type'];
		}

		public static function who( array $rule ) {
			$parts = array();
			foreach ( $rule['who']['plans'] as $id ) {
				$p = FW_Membership_Plans::get( $id );
				if ( $p ) {
					$parts[] = $p['name'];
				}
			}
			foreach ( $rule['who']['roles'] as $r ) {
				$parts[] = 'role ' . $r;
			}
			if ( $rule['who']['logged_in'] ) {
				$parts[] = 'anyone logged in';
			}
			return $parts ? implode( ', ', $parts ) : 'members of any plan';
		}
	}

endif;
