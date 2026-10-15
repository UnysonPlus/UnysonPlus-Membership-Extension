<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * More AI Assistant abilities for Membership: running the site after it is set up.
 *
 *   membership-setup-pages      make the Join / Log in / My account / Checkout pages with their
 *                               elements and point Settings (and Payments) at them
 *   membership-modules          switch modules on or off (option snapshotted: undoable)
 *   membership-find-members     look people up by email, name, plan or status (read-only)
 *   membership-manage-member    give a plan, extend, change plan, cancel, end, reactivate
 *   membership-payment-settings currency, checkout page, upgrades, invoices, which ways to pay,
 *                               bank-transfer text (never secret keys: those stay on the screen)
 *   membership-save-coupon      create or update a coupon, matched by code
 *   membership-save-course      a course with its lessons, quizzes, drip and certificate
 *   membership-emails           read the emails, or change one (option snapshotted: undoable)
 *   membership-profile-fields   read the fields, or add / change / remove one (snapshotted)
 *   membership-sell-item        sell one post, page or course on its own
 *   membership-report           the numbers from Reports (read-only, totals only)
 *
 * Every ability checks the Membership capability. Changes to Membership's own tables
 * (members, coupons, courses) are not covered by undo_change; the reply says what changed
 * and where to edit it.
 */

if ( ! function_exists( 'fw_ext_membership_ai_register_manage' ) ) :

	function fw_ext_membership_ai_register_manage() {
		if ( ! function_exists( 'fw_ai_register_ability' ) || ! class_exists( 'FW_Membership_Service' ) ) {
			return;
		}
		$cap = FW_Membership_Service::capability();

		fw_ai_register_ability( 'membership-setup-pages', array(
			'label'       => __( 'Create the member pages', 'fw' ),
			'description' => 'Creates the pages a membership site needs, each with its element placed in the page builder, and points the settings at them: join (Pricing Plans; becomes Settings > Join page, where the Join button on prompts goes), login (Member Login), account (Member Account), checkout (Membership Checkout; only while the Payments module is on; becomes Payments > Checkout page). pages: which of join | login | account | checkout to make (default: all that are missing). A page the settings already point to is kept, never replaced. titles: { join?, login?, account?, checkout? } to name them. Pages are published. Not undoable with undo_change (delete the pages to undo).',
			'input'       => array(
				'pages'  => array( 'type' => 'array' ),
				'titles' => array( 'type' => 'object' ),
			),
			'permission'  => $cap,
			'idempotent'  => true,
			'execute'     => 'fw_ext_membership_ai_setup_pages',
		) );

		fw_ai_register_ability( 'membership-modules', array(
			'label'       => __( 'Switch Membership modules on or off', 'fw' ),
			'description' => 'Switches Membership modules on or off. on: [slugs], off: [slugs]. Slugs: payments, coupons, tax, gifts (these three need payments), drip, paywall (metered free reads), files (protected downloads), retention, groups (team plans), directory, cards, woocommerce (needs WooCommerce), analytics (Reports), security (devices, login links, passkeys, log), importers, courses. crm switches itself with the CRM extension. A module that is off loads no code; its data is kept. The change applies from the next page load. Undoable with undo_change.',
			'input'       => array(
				'on'  => array( 'type' => 'array' ),
				'off' => array( 'type' => 'array' ),
			),
			'permission'  => $cap,
			'idempotent'  => true,
			'execute'     => 'fw_ext_membership_ai_modules',
		) );

		fw_ai_register_ability( 'membership-find-members', array(
			'label'       => __( 'Find members', 'fw' ),
			'description' => 'Looks up memberships: search (email or name, part of it is enough), plan (slug), status (active | trialing | past_due | paused | cancelled | expired | refunded | pending, or "access" for everyone who currently has access), limit (1-50, default 20). Returns each membership id, the person (name, email), plan, status, start, end and renewal dates and how it began, plus the total. Use it before membership-manage-member.',
			'input'       => array(
				'search' => array( 'type' => 'string' ),
				'plan'   => array( 'type' => 'string' ),
				'status' => array( 'type' => 'string' ),
				'limit'  => array( 'type' => 'integer' ),
			),
			'permission'  => $cap,
			'readonly'    => true,
			'execute'     => 'fw_ext_membership_ai_find_members',
		) );

		fw_ai_register_ability( 'membership-manage-member', array(
			'label'       => __( 'Change a membership', 'fw' ),
			'description' => 'Changes one person\'s membership. action: grant (give email a plan; create_account: true makes an account for a new email, with no email sent unless notify: true) | extend (until: YYYY-MM-DD, "+1 month" style, or empty for one more plan length) | change_plan (to plan) | cancel (stops renewing; access continues to the end date) | end (access stops now) | reactivate. Identify the membership by membership (id, from membership-find-members), or by email + plan. grant also takes ends: YYYY-MM-DD | never (default: as the plan says). Cancel, end and reactivate are passed on to the payment service when the membership is paid by card or PayPal; change_plan is not (members change paid plans themselves on their account page). Not undoable with undo_change.',
			'input'       => array(
				'action'         => array( 'type' => 'string', 'enum' => array( 'grant', 'extend', 'change_plan', 'cancel', 'end', 'reactivate' ) ),
				'membership'     => array( 'type' => 'integer' ),
				'email'          => array( 'type' => 'string' ),
				'plan'           => array( 'type' => 'string' ),
				'to'             => array( 'type' => 'string' ),
				'until'          => array( 'type' => 'string' ),
				'ends'           => array( 'type' => 'string' ),
				'create_account' => array( 'type' => 'boolean' ),
				'notify'         => array( 'type' => 'boolean' ),
				'name'           => array( 'type' => 'string' ),
			),
			'required'    => array( 'action' ),
			'permission'  => $cap,
			'execute'     => 'fw_ext_membership_ai_manage_member',
		) );

		fw_ai_register_ability( 'membership-payment-settings', array(
			'label'       => __( 'Change payment settings', 'fw' ),
			'description' => 'Reads (no input) or updates the Payments module settings; only the keys given change. currency (3 letters), checkout_page (page id, slug or title with the Membership Checkout element), terms_url, proration: prorate (charge the difference now) | next (new price from the next renewal), invoice { prefix, business, address, tax_id, footer }, gateways { stripe?: { enabled, mode: test | live }, paypal?: { enabled, mode: sandbox | live }, offline?: { enabled, label (the name buyers see, e.g. "Bank transfer"), instructions (shown after checkout; [reference] is replaced by the payment reference) } }. Secret keys, client secrets and webhook secrets are never set here: tell the owner to paste them under Membership > Payments > Payment settings. Bank transfer (offline) works for one-off prices only; repeating prices need card or PayPal. Tax rates are set on that screen too. Needs the Payments module. Undoable with undo_change.',
			'input'       => array(
				'currency'      => array( 'type' => 'string' ),
				'checkout_page' => array( 'type' => array( 'integer', 'string' ) ),
				'terms_url'     => array( 'type' => 'string' ),
				'proration'     => array( 'type' => 'string' ),
				'invoice'       => array( 'type' => 'object' ),
				'gateways'      => array( 'type' => 'object' ),
			),
			'permission'  => $cap,
			'idempotent'  => true,
			'execute'     => 'fw_ext_membership_ai_payment_settings',
		) );

		fw_ai_register_ability( 'membership-save-coupon', array(
			'label'       => __( 'Create or update a coupon', 'fw' ),
			'description' => 'Creates or updates a coupon matched by code. code (letters, numbers, - and _), description, type: percent | fixed, amount (a percentage up to 100, or money), currency (for fixed), duration: once (first payment) | repeating (months) | forever, months, plans: [plan slugs] (empty = every plan), max_uses (0 = no limit), per_user (default 1), starts / expires (YYYY-MM-DD), status: active | archived. A link with ?coupon=CODE on the checkout fills it in. Needs the Coupons module. Not undoable with undo_change.',
			'input'       => array(
				'code'        => array( 'type' => 'string', 'minLength' => 1 ),
				'description' => array( 'type' => 'string' ),
				'type'        => array( 'type' => 'string' ),
				'amount'      => array( 'type' => 'number' ),
				'currency'    => array( 'type' => 'string' ),
				'duration'    => array( 'type' => 'string' ),
				'months'      => array( 'type' => 'integer' ),
				'plans'       => array( 'type' => 'array' ),
				'max_uses'    => array( 'type' => 'integer' ),
				'per_user'    => array( 'type' => 'integer' ),
				'starts'      => array( 'type' => 'string' ),
				'expires'     => array( 'type' => 'string' ),
				'status'      => array( 'type' => 'string' ),
			),
			'required'    => array( 'code' ),
			'permission'  => $cap,
			'idempotent'  => true,
			'execute'     => 'fw_ext_membership_ai_save_coupon',
		) );

		fw_ai_register_ability( 'membership-save-course', array(
			'label'       => __( 'Create or update a course', 'fw' ),
			'description' => 'Creates or updates a course (matched by title or slug) and its lessons (matched by title inside the course, in the order given). title, slug, description (the course page text), who: plans | logged_in | open, plans: [plan slugs] (for who=plans), certificate: bool, certificate_page (page id, slug or title designed with {name} {course} {date} {code} {verify_url}; empty = the built-in design), price (sell it on its own; 0 or empty = not for sale), lessons: [ { title, module (lessons with the same module are grouped), content (HTML), opens_after_days, quiz (text: "Q: question" then "* right" / "- wrong" answer lines, several * = tick all that apply, "= typed answer" lines; blank line between questions), pass_mark (default 70), quiz_gates (later lessons wait until it is passed) } ]. Lessons not listed are left alone. status: publish | draft (default publish). Needs the Courses module. Not undoable with undo_change.',
			'input'       => array(
				'title'            => array( 'type' => 'string', 'minLength' => 1 ),
				'slug'             => array( 'type' => 'string' ),
				'description'      => array( 'type' => 'string' ),
				'who'              => array( 'type' => 'string' ),
				'plans'            => array( 'type' => 'array' ),
				'certificate'      => array( 'type' => 'boolean' ),
				'certificate_page' => array( 'type' => array( 'integer', 'string' ) ),
				'price'            => array( 'type' => 'number' ),
				'lessons'          => array( 'type' => 'array' ),
				'status'           => array( 'type' => 'string' ),
			),
			'required'    => array( 'title' ),
			'permission'  => $cap,
			'idempotent'  => true,
			'execute'     => 'fw_ext_membership_ai_save_course',
		) );

		fw_ai_register_ability( 'membership-emails', array(
			'label'       => __( 'Read or change member emails', 'fw' ),
			'description' => 'With no key: lists every email Membership sends (key, label, who gets it, when, on/off, subject) and the placeholders. With key: changes that email; enabled (bool; the confirmation email cannot be switched off), subject, body (plain text, placeholders like {first_name} {plan} {account_url}). An empty subject or body goes back to the default text. Undoable with undo_change.',
			'input'       => array(
				'key'     => array( 'type' => 'string' ),
				'enabled' => array( 'type' => 'boolean' ),
				'subject' => array( 'type' => 'string' ),
				'body'    => array( 'type' => 'string' ),
			),
			'permission'  => $cap,
			'idempotent'  => true,
			'execute'     => 'fw_ext_membership_ai_emails',
		) );

		fw_ai_register_ability( 'membership-profile-fields', array(
			'label'       => __( 'Read or change profile fields', 'fw' ),
			'description' => 'With no key: lists the profile fields members fill in. With key: adds or changes that field (label, type: text | textarea | select | checkbox | date | number | tel | url, choices: [..] for select, required, plans: [plan slugs] (empty = everyone), member_edit (members may change it), directory: hidden | members | public). remove: true deletes the field (values already saved on accounts stay). Undoable with undo_change.',
			'input'       => array(
				'key'         => array( 'type' => 'string' ),
				'label'       => array( 'type' => 'string' ),
				'type'        => array( 'type' => 'string' ),
				'choices'     => array( 'type' => 'array' ),
				'required'    => array( 'type' => 'boolean' ),
				'plans'       => array( 'type' => 'array' ),
				'member_edit' => array( 'type' => 'boolean' ),
				'directory'   => array( 'type' => 'string' ),
				'remove'      => array( 'type' => 'boolean' ),
			),
			'permission'  => $cap,
			'idempotent'  => true,
			'execute'     => 'fw_ext_membership_ai_profile_fields',
		) );

		fw_ai_register_ability( 'membership-sell-item', array(
			'label'       => __( 'Sell one post or page on its own', 'fw' ),
			'description' => 'Puts one post, page or course on sale without a plan (pay per item): post (id, slug or title), price (0 = take it off sale; buyers keep access), days (how long a purchase gives access; 0 = for ever), currency (default: the payment currency). The item also needs an access rule (or the Membership box on it) to be locked; this ability does not lock it. Needs the Payments module. Not undoable with undo_change.',
			'input'       => array(
				'post'     => array( 'type' => array( 'integer', 'string' ) ),
				'price'    => array( 'type' => 'number' ),
				'days'     => array( 'type' => 'integer' ),
				'currency' => array( 'type' => 'string' ),
			),
			'required'    => array( 'post', 'price' ),
			'permission'  => $cap,
			'idempotent'  => true,
			'execute'     => 'fw_ext_membership_ai_sell_item',
		) );

		fw_ai_register_ability( 'membership-report', array(
			'label'       => __( 'Membership numbers', 'fw' ),
			'description' => 'The Reports numbers, as totals: monthly recurring revenue and paying members now, lifetime value, trial conversion, members by plan, and month by month (members at start, new, upgrades, downgrades, lost, churn %, failed / recovered payments, revenue) for the last months (months: 1-24, default 6). Amounts per currency. Never a person. Needs the Reports module.',
			'input'       => array(
				'months' => array( 'type' => 'integer' ),
			),
			'permission'  => $cap,
			'readonly'    => true,
			'execute'     => 'fw_ext_membership_ai_report',
		) );
	}
	add_action( 'fw_ai_assistant_register_abilities', 'fw_ext_membership_ai_register_manage' );

	/* ---------------------------------------------------------------------- *
	 * Helpers
	 * ---------------------------------------------------------------------- */

	/** @return WP_Error|null An error when a module the ability needs is off. */
	function fw_ext_membership_ai_needs( $class, $module_label ) {
		return class_exists( $class ) ? null : new WP_Error( 'fw_mbr_ai_module', sprintf( 'The %1$s module is off. Switch it on first (membership-modules, or Membership > Modules).', $module_label ) );
	}

	/** @return array|null A plan by slug, id or name. */
	function fw_ext_membership_ai_plan( $ref ) {
		if ( is_numeric( $ref ) ) {
			return FW_Membership_Plans::get( (int) $ref );
		}
		$p = FW_Membership_Plans::get_by_slug( sanitize_title( (string) $ref ) );
		if ( $p ) {
			return $p;
		}
		foreach ( FW_Membership_Plans::all() as $plan ) {
			if ( 0 === strcasecmp( $plan['name'], trim( (string) $ref ) ) ) {
				return $plan;
			}
		}
		return null;
	}

	/** @return int[]|WP_Error Plan ids for a list of slugs / names. */
	function fw_ext_membership_ai_plan_ids( $refs ) {
		$ids = array();
		foreach ( (array) $refs as $ref ) {
			$p = fw_ext_membership_ai_plan( $ref );
			if ( ! $p ) {
				return new WP_Error( 'fw_mbr_ai_plan', sprintf( 'No plan "%s".', is_scalar( $ref ) ? $ref : '' ) );
			}
			$ids[] = (int) $p['id'];
		}
		return $ids;
	}

	/** @return array A membership as the abilities show it. */
	function fw_ext_membership_ai_membership_out( array $m ) {
		$u    = get_userdata( (int) $m['user_id'] );
		$plan = FW_Membership_Plans::get( (int) $m['plan_id'] );
		return array(
			'membership' => (int) $m['id'],
			'name'       => $u ? $u->display_name : '',
			'email'      => $u ? $u->user_email : '',
			'plan'       => $plan ? $plan['slug'] : '',
			'status'     => $m['status'],
			'started'    => (string) $m['started_at'],
			'ends'       => (string) $m['ends_at'],
			'renews'     => (string) $m['renews_at'],
			'source'     => (string) $m['source'],
			'paid_by'    => (string) $m['gateway'],
		);
	}

	/** @return array An element's atts with every option at its default (the views read missing atts as empty). */
	function fw_ext_membership_ai_atts( $tag, array $over = array() ) {
		$sc = function_exists( 'fw_ext' ) && fw_ext( 'shortcodes' ) ? fw_ext( 'shortcodes' )->get_shortcode( $tag ) : null;
		$d  = $sc ? fw_get_options_values_from_input( $sc->get_options(), array() ) : array();
		return (object) array_merge( is_array( $d ) ? $d : array(), $over );
	}

	/**
	 * A page holding one element (and an optional heading), stored the way the builder stores it
	 * (the kit's building-pages.md recipe): post row first, then the flat json + builder_active keys
	 * and the fw_options aggregate, written last with no render in between.
	 *
	 * @return int|WP_Error The page id.
	 */
	function fw_ext_membership_ai_builder_page( $title, $shortcode, $heading = '' ) {
		$items = array();
		if ( '' !== $heading ) {
			$items[] = array( 'type' => 'simple', 'shortcode' => 'special_heading', '_items' => array(), 'atts' => fw_ext_membership_ai_atts( 'special_heading', array( 'title' => $heading, 'heading' => 'h1' ) ) );
		}
		$items[] = array( 'type' => 'simple', 'shortcode' => $shortcode, '_items' => array(), 'atts' => fw_ext_membership_ai_atts( $shortcode ) );
		$json    = wp_json_encode( array( array( 'type' => 'section', 'atts' => (object) array(), '_items' => array( array( 'type' => 'column', 'width' => '1_1', 'atts' => (object) array(), '_items' => $items ) ) ) ) );
		$id      = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		// The markup as the post content (search, feeds, a fallback), by a direct row update: no
		// save_post re-sync, and no render that would cache an empty builder value.
		$ot = fw()->backend->option_type( 'page-builder' );
		if ( $ot && method_exists( $ot, 'json_to_shortcodes' ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_content' => $ot->json_to_shortcodes( $json ) ), array( 'ID' => $id ) );
			clean_post_cache( $id );
		}
		update_post_meta( $id, 'fw:opt:ext:pb:page-builder:json', wp_slash( $json ) );
		update_post_meta( $id, 'fw:opt:ext:pb:page-builder:builder_active', true );
		$fw                 = get_post_meta( $id, 'fw_options', true );
		$fw                 = is_array( $fw ) ? $fw : array();
		$fw['page-builder'] = array( 'json' => $json, 'builder_active' => true );
		update_post_meta( $id, 'fw_options', wp_slash( $fw ) );
		return $id;
	}

	/* ---------------------------------------------------------------------- *
	 * Abilities
	 * ---------------------------------------------------------------------- */

	function fw_ext_membership_ai_setup_pages( $in ) {
		$in       = (array) $in;
		$s        = FW_Membership_Settings::get();
		$payments = class_exists( 'FW_Membership_Gateways' );
		$want     = ! empty( $in['pages'] ) ? array_map( 'sanitize_key', (array) $in['pages'] ) : array( 'join', 'login', 'account', 'checkout' );
		$titles   = (array) ( $in['titles'] ?? array() );
		$spec     = array(
			'join'     => array( 'join_page', __( 'Join', 'fw' ), 'pricing_plans', __( 'Choose your membership', 'fw' ) ),
			'login'    => array( 'login_page', __( 'Log in', 'fw' ), 'member_login', '' ),
			'account'  => array( 'account_page', __( 'My account', 'fw' ), 'member_account', '' ),
			'checkout' => array( 'checkout_page', __( 'Checkout', 'fw' ), 'membership_checkout', '' ),
		);
		$made = array();
		$kept = array();
		$pay  = $payments ? FW_Membership_Gateways::settings() : array();
		foreach ( $want as $key ) {
			if ( ! isset( $spec[ $key ] ) ) {
				continue;
			}
			list( $setting, $default_title, $sc, $heading ) = $spec[ $key ];
			if ( 'checkout' === $key && ! $payments ) {
				$kept[] = 'checkout (skipped: the Payments module is off)';
				continue;
			}
			$current = 'checkout' === $key ? (int) ( $pay['checkout_page'] ?? 0 ) : (int) ( $s[ $setting ] ?? 0 );
			if ( $current && 'publish' === get_post_status( $current ) ) {
				$kept[] = $key . ': "' . get_the_title( $current ) . '" (#' . $current . ')';
				continue;
			}
			$title = sanitize_text_field( (string) ( $titles[ $key ] ?? $default_title ) );
			$id    = fw_ext_membership_ai_builder_page( $title, $sc, $heading );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			if ( 'checkout' === $key ) {
				$pay['checkout_page'] = $id;
			} else {
				$s[ $setting ] = $id;
			}
			$made[] = $key . ': "' . $title . '" ' . get_permalink( $id );
		}
		FW_Membership_Settings::save( $s );
		if ( $payments && isset( $pay['checkout_page'] ) ) {
			FW_Membership_Gateways::save_settings( $pay );
		}
		return array(
			'ok'      => true,
			'message' => ( $made ? 'Created ' . implode( '; ', $made ) . '. ' : 'Nothing new was needed. ' ) . ( $kept ? 'Kept ' . implode( '; ', $kept ) . '. ' : '' ) . 'Settings now point to these pages; add them to the menu if you want them there.',
			'created' => $made,
		);
	}

	function fw_ext_membership_ai_modules( $in ) {
		$in  = (array) $in;
		$reg = FW_Membership_Modules::registry();
		$rev = function_exists( 'fw_ai_snapshot' ) ? fw_ai_snapshot( array( 'options' => array( FW_Membership_Modules::OPTION ) ), 'membership-modules', 'Membership modules' ) : 0;
		$on  = array_map( 'sanitize_key', (array) ( $in['on'] ?? array() ) );
		$off = array_map( 'sanitize_key', (array) ( $in['off'] ?? array() ) );
		// Payments first, so the modules that need it can follow in the same call.
		usort( $on, function ( $a, $b ) {
			return ( 'payments' === $b ) - ( 'payments' === $a );
		} );
		$done   = array();
		$errors = array();
		foreach ( $on as $slug ) {
			if ( ! isset( $reg[ $slug ] ) ) {
				$errors[] = sprintf( 'No module "%s".', $slug );
				continue;
			}
			if ( ! empty( $reg[ $slug ]['auto'] ) ) {
				$errors[] = sprintf( '%s switches itself on with its extension.', $reg[ $slug ]['label'] );
				continue;
			}
			if ( isset( $reg[ $slug ]['requires'] ) && ! call_user_func( $reg[ $slug ]['requires'] ) ) {
				$errors[] = sprintf( '%1$s: %2$s', $reg[ $slug ]['label'], $reg[ $slug ]['requires_text'] ?? 'not available here.' );
				continue;
			}
			$r = FW_Membership_Modules::set( $slug, true );
			is_wp_error( $r ) ? $errors[] = $r->get_error_message() : $done[] = $reg[ $slug ]['label'] . ' on';
		}
		foreach ( $off as $slug ) {
			if ( isset( $reg[ $slug ] ) && empty( $reg[ $slug ]['auto'] ) ) {
				FW_Membership_Modules::set( $slug, false );
				$done[] = $reg[ $slug ]['label'] . ' off';
			}
		}
		return array(
			'ok'       => ! $errors || $done,
			'message'  => ( $done ? implode( ', ', $done ) . '. The change applies from the next page load. ' : '' ) . implode( ' ', $errors ),
			'revision' => $rev,
			'on'       => array_values( (array) get_option( FW_Membership_Modules::OPTION, array() ) ),
		);
	}

	function fw_ext_membership_ai_find_members( $in ) {
		$in   = (array) $in;
		$args = array( 'per_page' => max( 1, min( 50, (int) ( $in['limit'] ?? 20 ) ) ) );
		if ( ! empty( $in['search'] ) ) {
			$args['search'] = sanitize_text_field( (string) $in['search'] );
		}
		if ( ! empty( $in['plan'] ) ) {
			$p = fw_ext_membership_ai_plan( $in['plan'] );
			if ( ! $p ) {
				return new WP_Error( 'fw_mbr_ai_plan', sprintf( 'No plan "%s".', $in['plan'] ) );
			}
			$args['plan_id'] = $p['id'];
		}
		if ( ! empty( $in['status'] ) ) {
			$st             = sanitize_key( (string) $in['status'] );
			$args['status'] = 'access' === $st ? 'access' : array( $st );
		}
		$rows = FW_Membership_Memberships::query( $args );
		return array(
			'total'       => FW_Membership_Memberships::count( $args ),
			'memberships' => array_map( 'fw_ext_membership_ai_membership_out', $rows ),
		);
	}

	function fw_ext_membership_ai_manage_member( $in ) {
		$in     = (array) $in;
		$action = sanitize_key( (string) $in['action'] );
		$email  = sanitize_email( (string) ( $in['email'] ?? '' ) );
		$plan   = ! empty( $in['plan'] ) ? fw_ext_membership_ai_plan( $in['plan'] ) : null;
		if ( ! empty( $in['plan'] ) && ! $plan ) {
			return new WP_Error( 'fw_mbr_ai_plan', sprintf( 'No plan "%s".', $in['plan'] ) );
		}

		if ( 'grant' === $action ) {
			if ( ! is_email( $email ) || ! $plan ) {
				return new WP_Error( 'fw_mbr_ai_grant', 'grant needs an email and a plan.' );
			}
			$user = get_user_by( 'email', $email );
			if ( ! $user ) {
				if ( empty( $in['create_account'] ) ) {
					return new WP_Error( 'fw_mbr_ai_no_user', sprintf( 'No account uses %s. Pass create_account: true to make one.', $email ) );
				}
				$name = trim( (string) ( $in['name'] ?? '' ) );
				$uid  = wp_insert_user( array(
					'user_login'   => class_exists( 'FW_Membership_Checkout' ) ? FW_Membership_Checkout::username( $email ) : sanitize_user( strstr( $email, '@', true ) . wp_rand( 100, 999 ), true ),
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24 ),
					'first_name'   => strtok( $name, ' ' ) ?: '',
					'display_name' => '' !== $name ? $name : strstr( $email, '@', true ),
					'role'         => get_option( 'default_role', 'subscriber' ),
				) );
				if ( is_wp_error( $uid ) ) {
					return $uid;
				}
				if ( ! empty( $in['notify'] ) ) {
					wp_new_user_notification( $uid, null, 'user' );
				}
				$user = get_userdata( $uid );
			}
			$ends = (string) ( $in['ends'] ?? '' );
			$id   = FW_Membership_Service::grant( $user->ID, $plan['id'], array( 'source' => 'manual', 'ends' => 'never' === $ends ? 'never' : ( '' !== $ends ? sanitize_text_field( $ends ) : '' ), 'note' => 'Added through the AI Assistant.' ) );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$m = FW_Membership_Memberships::get( $id );
			return array( 'ok' => true, 'message' => sprintf( 'Gave %1$s the %2$s plan. See them under Membership > Members.', $email, $plan['name'] ), 'membership' => $m ? fw_ext_membership_ai_membership_out( $m ) : null );
		}

		// Every other action works on one membership.
		$m = ! empty( $in['membership'] ) ? FW_Membership_Memberships::get( (int) $in['membership'] ) : null;
		if ( ! $m && is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			$list = $user ? FW_Membership_Memberships::query( array_filter( array( 'user_id' => $user->ID, 'plan_id' => $plan ? $plan['id'] : 0, 'per_page' => 10 ) ) ) : array();
			if ( count( $list ) > 1 ) {
				return new WP_Error( 'fw_mbr_ai_many', sprintf( '%1$s has %2$d memberships; pass plan, or membership (the id) from membership-find-members.', $email, count( $list ) ) );
			}
			$m = $list ? $list[0] : null;
		}
		if ( ! $m ) {
			return new WP_Error( 'fw_mbr_ai_membership', 'No such membership. Look it up with membership-find-members.' );
		}
		switch ( $action ) {
			case 'extend':
				$r   = FW_Membership_Service::extend( $m['id'], sanitize_text_field( (string) ( $in['until'] ?? '' ) ) );
				$msg = 'Extended.';
				break;
			case 'change_plan':
				$to = fw_ext_membership_ai_plan( $in['to'] ?? '' );
				if ( ! $to ) {
					return new WP_Error( 'fw_mbr_ai_plan', 'change_plan needs to: a plan slug.' );
				}
				$r   = FW_Membership_Service::change_plan( $m['id'], $to['id'] );
				$msg = sprintf( 'Moved to %s.', $to['name'] );
				break;
			case 'cancel':
				$r   = FW_Membership_Service::cancel( $m['id'] );
				$msg = 'Cancelled: it will not renew, and access continues until the end date.';
				break;
			case 'end':
				$r   = FW_Membership_Service::expire( $m['id'], 'Ended through the AI Assistant.' );
				$msg = 'Ended: access has stopped.';
				break;
			case 'reactivate':
				$r   = FW_Membership_Service::reactivate( $m['id'] );
				$msg = 'Active again.';
				break;
			default:
				return new WP_Error( 'fw_mbr_ai_action', 'Unknown action.' );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$m = FW_Membership_Memberships::get( $m['id'] );
		return array( 'ok' => true, 'message' => $msg, 'membership' => fw_ext_membership_ai_membership_out( $m ) );
	}

	function fw_ext_membership_ai_payment_settings( $in ) {
		$err = fw_ext_membership_ai_needs( 'FW_Membership_Gateways', 'Payments' );
		if ( $err ) {
			return $err;
		}
		$in  = (array) $in;
		$old = FW_Membership_Gateways::settings();
		$out = function ( $s ) {
			$g = array();
			foreach ( FW_Membership_Gateways::all() as $id => $gw ) {
				$row = (array) ( $s['gateways'][ $id ] ?? array() );
				$vis = array( 'enabled' => ! empty( $row['enabled'] ), 'ready' => $gw->is_ready(), 'title' => $gw->title() );
				foreach ( $gw->settings_fields() as $k => $f ) {
					if ( 'password' !== $f['type'] ) {
						$vis[ $k ] = $row[ $k ] ?? '';
					} else {
						$vis[ $k ] = '' !== (string) ( $row[ $k ] ?? '' ) ? '(saved)' : '(empty: paste it on the Payment settings screen)';
					}
				}
				$g[ $id ] = $vis;
			}
			return array( 'currency' => $s['currency'], 'checkout_page' => $s['checkout_page'] ? get_the_title( $s['checkout_page'] ) . ' (#' . $s['checkout_page'] . ')' : '', 'terms_url' => $s['terms_url'], 'proration' => $s['proration'], 'invoice' => $s['invoice'] ?? array(), 'gateways' => $g );
		};
		if ( ! $in ) {
			return $out( $old );
		}
		$new = $old;
		foreach ( array( 'currency', 'terms_url', 'proration' ) as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$new[ $k ] = $in[ $k ];
			}
		}
		if ( array_key_exists( 'checkout_page', $in ) ) {
			$page                 = FW_Membership_AI_Labels::find_post( $in['checkout_page'], 'page' );
			$new['checkout_page'] = $page ? $page->ID : 0;
		}
		if ( isset( $in['invoice'] ) ) {
			$new['invoice'] = array_merge( (array) ( $old['invoice'] ?? array() ), (array) $in['invoice'] );
		}
		$refused = array();
		foreach ( FW_Membership_Gateways::all() as $id => $gw ) {
			$row = (array) ( $old['gateways'][ $id ] ?? array() );
			foreach ( $gw->settings_fields() as $k => $f ) {
				if ( 'password' === $f['type'] ) {
					unset( $row[ $k ] ); // blank keeps the stored secret
				}
			}
			foreach ( (array) ( $in['gateways'][ $id ] ?? array() ) as $k => $v ) {
				$fields = $gw->settings_fields();
				if ( isset( $fields[ $k ] ) && 'password' === $fields[ $k ]['type'] ) {
					$refused[] = $id . '.' . $k;
					continue;
				}
				$row[ $k ] = $v;
			}
			$new['gateways'][ $id ] = $row;
		}
		$rev = function_exists( 'fw_ai_snapshot' ) ? fw_ai_snapshot( array( 'options' => array( FW_Membership_Gateways::OPTION ) ), 'membership-payments', 'Membership payment settings' ) : 0;
		FW_Membership_Gateways::save_settings( $new );
		return array(
			'ok'       => true,
			'message'  => 'Payment settings saved.' . ( $refused ? ' Not set (secrets are pasted by the owner under Membership > Payments > Payment settings): ' . implode( ', ', $refused ) . '.' : '' ),
			'revision' => $rev,
			'settings' => $out( FW_Membership_Gateways::settings() ),
		);
	}

	function fw_ext_membership_ai_save_coupon( $in ) {
		$err = fw_ext_membership_ai_needs( 'FW_Membership_Coupons', 'Coupons' );
		if ( $err ) {
			return $err;
		}
		$in    = (array) $in;
		$have  = FW_Membership_Coupons::get_by_code( (string) $in['code'] );
		$plans = isset( $in['plans'] ) ? fw_ext_membership_ai_plan_ids( $in['plans'] ) : ( $have['plans'] ?? array() );
		if ( is_wp_error( $plans ) ) {
			return $plans;
		}
		$data = array_merge( $have ? $have : array(), array_intersect_key( $in, array_flip( array( 'code', 'description', 'type', 'amount', 'currency', 'duration', 'months', 'max_uses', 'per_user', 'status' ) ) ) );
		$data['plans'] = $plans;
		if ( isset( $in['starts'] ) ) {
			$data['starts_at'] = $in['starts'];
		}
		if ( isset( $in['expires'] ) ) {
			$data['expires_at'] = $in['expires'];
		}
		$id = FW_Membership_Coupons::save( $data, $have ? (int) $have['id'] : 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$c = FW_Membership_Coupons::get( $id );
		return array( 'ok' => true, 'message' => sprintf( '%1$s the coupon %2$s: %3$s. Edit it under Membership > Coupons.', $have ? 'Updated' : 'Created', $c['code'], FW_Membership_Coupons::label( $c ) ), 'coupon' => $c );
	}

	function fw_ext_membership_ai_save_course( $in ) {
		$err = fw_ext_membership_ai_needs( 'FW_Membership_Courses', 'Courses' );
		if ( $err ) {
			return $err;
		}
		$in     = (array) $in;
		$type   = FW_Membership_Courses::COURSE;
		$status = 'draft' === ( $in['status'] ?? '' ) ? 'draft' : 'publish';
		$course = ! empty( $in['slug'] ) ? get_page_by_path( sanitize_title( (string) $in['slug'] ), OBJECT, $type ) : null;
		if ( ! $course ) {
			$found  = get_posts( array( 'post_type' => $type, 'title' => (string) $in['title'], 'post_status' => 'any', 'numberposts' => 1 ) );
			$course = $found ? $found[0] : null;
		}
		$post = array( 'post_type' => $type, 'post_status' => $status, 'post_title' => (string) $in['title'] );
		if ( isset( $in['description'] ) ) {
			$post['post_content'] = wp_kses_post( (string) $in['description'] );
		}
		if ( ! empty( $in['slug'] ) ) {
			$post['post_name'] = sanitize_title( (string) $in['slug'] );
		}
		$cid = $course ? wp_update_post( array_merge( $post, array( 'ID' => $course->ID ) ), true ) : wp_insert_post( $post, true );
		if ( is_wp_error( $cid ) ) {
			return $cid;
		}
		if ( isset( $in['who'] ) || isset( $in['plans'] ) ) {
			$who   = in_array( $in['who'] ?? 'plans', array( 'plans', 'logged_in', 'open' ), true ) ? ( $in['who'] ?? 'plans' ) : 'plans';
			$plans = fw_ext_membership_ai_plan_ids( $in['plans'] ?? array() );
			if ( is_wp_error( $plans ) ) {
				return $plans;
			}
			update_post_meta( $cid, '_fw_course_access', array( 'who' => $who, 'plans' => $plans ) );
		}
		if ( isset( $in['certificate'] ) ) {
			update_post_meta( $cid, '_fw_certificate', $in['certificate'] ? 1 : 0 );
		}
		if ( array_key_exists( 'certificate_page', $in ) ) {
			$page = $in['certificate_page'] ? FW_Membership_AI_Labels::find_post( $in['certificate_page'], 'page' ) : null;
			update_post_meta( $cid, '_fw_certificate_page', $page ? $page->ID : 0 );
		}
		if ( isset( $in['price'] ) ) {
			$sold = fw_ext_membership_ai_needs( 'FW_Membership_Single_Purchase', 'Payments' );
			if ( $sold ) {
				return $sold;
			}
			FW_Membership_Single_Purchase::sell( 'post', $cid, (string) $in['title'], (float) $in['price'] );
		}
		$have = array();
		foreach ( FW_Membership_Courses::lessons( $cid ) as $l ) {
			$have[ strtolower( $l->post_title ) ] = $l->ID;
		}
		$lessons = array();
		foreach ( array_values( (array) ( $in['lessons'] ?? array() ) ) as $n => $l ) {
			$l     = (array) $l;
			$title = trim( (string) ( $l['title'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			$row = array( 'post_type' => FW_Membership_Courses::LESSON, 'post_status' => $status, 'post_title' => $title, 'post_parent' => $cid, 'menu_order' => $n + 1 );
			if ( isset( $l['content'] ) ) {
				$row['post_content'] = wp_kses_post( (string) $l['content'] );
			}
			$lid = isset( $have[ strtolower( $title ) ] ) ? wp_update_post( array_merge( $row, array( 'ID' => $have[ strtolower( $title ) ] ) ), true ) : wp_insert_post( $row, true );
			if ( is_wp_error( $lid ) ) {
				return $lid;
			}
			foreach ( array( 'module' => '_fw_module', 'opens_after_days' => '_fw_drip_days', 'quiz' => '_fw_quiz', 'pass_mark' => '_fw_quiz_pass' ) as $k => $meta ) {
				if ( isset( $l[ $k ] ) ) {
					update_post_meta( $lid, $meta, 'quiz' === $k || 'module' === $k ? sanitize_textarea_field( (string) $l[ $k ] ) : max( 0, (int) $l[ $k ] ) );
				}
			}
			if ( isset( $l['quiz_gates'] ) ) {
				update_post_meta( $lid, '_fw_quiz_gate', $l['quiz_gates'] ? 1 : 0 );
			}
			$lessons[] = $title . ( ! empty( $l['quiz'] ) ? ' (' . count( FW_Membership_Quizzes::parse( (string) $l['quiz'] ) ) . ' quiz questions)' : '' );
		}
		FW_Membership_Courses::sync_rule( $cid );
		return array(
			'ok'      => true,
			'message' => sprintf( '%1$s the course "%2$s" (%3$s)%4$s. Edit it under Courses; check it with "View as" in the admin bar.', $course ? 'Updated' : 'Created', $in['title'], get_permalink( $cid ), $lessons ? ' with lessons: ' . implode( '; ', $lessons ) : '' ),
			'course'  => $cid,
		);
	}

	function fw_ext_membership_ai_emails( $in ) {
		$in  = (array) $in;
		$all = FW_Membership_Emails::all();
		if ( empty( $in['key'] ) ) {
			$list = array();
			foreach ( $all as $key => $e ) {
				$list[] = array( 'key' => $key, 'label' => $e['label'] ?? $key, 'to' => $e['to'] ?? '', 'when' => $e['when'] ?? '', 'enabled' => ! empty( $e['enabled'] ), 'subject' => $e['subject'] );
			}
			return array( 'emails' => $list, 'placeholders' => FW_Membership_Emails::placeholders() );
		}
		$key = sanitize_key( (string) $in['key'] );
		if ( ! isset( $all[ $key ] ) ) {
			return new WP_Error( 'fw_mbr_ai_email', sprintf( 'No email "%s". Call membership-emails with no key for the list.', $key ) );
		}
		// Keep every other email as stored (an empty stored text means "the default").
		$stored = (array) get_option( FW_Membership_Emails::OPTION, array() );
		$values = array();
		foreach ( $all as $k => $e ) {
			$values[ $k ] = array_merge( array( 'enabled' => ! empty( $e['enabled'] ), 'subject' => '', 'body' => '' ), (array) ( $stored[ $k ] ?? array() ) );
		}
		foreach ( array( 'enabled', 'subject', 'body' ) as $f ) {
			if ( array_key_exists( $f, $in ) ) {
				$values[ $key ][ $f ] = $in[ $f ];
			}
		}
		$rev = function_exists( 'fw_ai_snapshot' ) ? fw_ai_snapshot( array( 'options' => array( FW_Membership_Emails::OPTION ) ), 'membership-emails', 'Membership emails' ) : 0;
		FW_Membership_Emails::save( $values );
		$e = FW_Membership_Emails::all()[ $key ];
		return array( 'ok' => true, 'message' => sprintf( 'Saved the "%s" email.', $e['label'] ?? $key ), 'revision' => $rev, 'email' => array( 'enabled' => ! empty( $e['enabled'] ), 'subject' => $e['subject'], 'body' => $e['body'] ) );
	}

	function fw_ext_membership_ai_profile_fields( $in ) {
		$in     = (array) $in;
		$fields = FW_Membership_Profile::fields();
		if ( empty( $in['key'] ) ) {
			return array( 'fields' => array_values( $fields ) );
		}
		$key = sanitize_key( (string) $in['key'] );
		if ( ! empty( $in['remove'] ) ) {
			unset( $fields[ $key ] );
		} else {
			$f = $fields[ $key ] ?? array( 'key' => $key, 'label' => $key, 'type' => 'text' );
			foreach ( array( 'label', 'type', 'choices', 'required', 'member_edit', 'directory' ) as $k ) {
				if ( array_key_exists( $k, $in ) ) {
					$f[ $k ] = $in[ $k ];
				}
			}
			if ( isset( $in['plans'] ) ) {
				$ids = fw_ext_membership_ai_plan_ids( $in['plans'] );
				if ( is_wp_error( $ids ) ) {
					return $ids;
				}
				$f['plans'] = $ids;
			}
			$fields[ $key ] = $f;
		}
		$rev = function_exists( 'fw_ai_snapshot' ) ? fw_ai_snapshot( array( 'options' => array( FW_Membership_Profile::OPTION ) ), 'membership-fields', 'Membership profile fields' ) : 0;
		$r   = FW_Membership_Profile::save_fields( array_values( $fields ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array( 'ok' => true, 'message' => ! empty( $in['remove'] ) ? sprintf( 'Removed the field %s (values already saved on accounts stay).', $key ) : sprintf( 'Saved the field %s.', $key ), 'revision' => $rev, 'fields' => array_values( FW_Membership_Profile::fields() ) );
	}

	function fw_ext_membership_ai_sell_item( $in ) {
		$err = fw_ext_membership_ai_needs( 'FW_Membership_Single_Purchase', 'Payments' );
		if ( $err ) {
			return $err;
		}
		$in   = (array) $in;
		$post = FW_Membership_AI_Labels::find_post( $in['post'] );
		if ( ! $post ) {
			return new WP_Error( 'fw_mbr_ai_post', 'Could not find that post or page.' );
		}
		$r = FW_Membership_Single_Purchase::sell( 'post', $post->ID, $post->post_title, (float) $in['price'], (int) ( $in['days'] ?? 0 ), (string) ( $in['currency'] ?? '' ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( class_exists( 'FW_Membership_Courses' ) && FW_Membership_Courses::COURSE === $post->post_type ) {
			FW_Membership_Courses::sync_rule( $post->ID );
		}
		return array( 'ok' => true, 'message' => (float) $in['price'] > 0 ? sprintf( '"%1$s" is on sale for %2$s. Lock it with an access rule (or its Membership box) so people buy it to see it.', $post->post_title, number_format_i18n( (float) $in['price'], 2 ) ) : sprintf( '"%s" is no longer for sale; people who bought it keep access.', $post->post_title ) );
	}

	function fw_ext_membership_ai_report( $in ) {
		$err = fw_ext_membership_ai_needs( 'FW_Membership_Reports', 'Reports' );
		if ( $err ) {
			return $err;
		}
		$n      = max( 1, min( 24, (int) ( ( (array) $in )['months'] ?? 6 ) ) );
		$months = array();
		foreach ( FW_Membership_Reports::monthly_table( $n ) as $ym => $r ) {
			$months[ $ym ] = array( 'members_at_start' => $r['start_members'], 'new' => $r['new'], 'upgrades' => $r['upgrades'], 'downgrades' => $r['downgrades'], 'lost' => $r['lost'], 'churn_percent' => $r['churn'], 'failed_payments' => $r['failed'], 'recovered_payments' => $r['recovered'], 'revenue' => FW_Membership_Reports::money_list( $r['revenue'] ) );
		}
		$mrr       = FW_Membership_Reports::mrr();
		$mrr_plans = array();
		foreach ( $mrr['plans'] as $pid => $money ) {
			$p = FW_Membership_Plans::get( $pid );
			$mrr_plans[ $p ? $p['slug'] : (string) $pid ] = FW_Membership_Reports::money_list( $money );
		}
		$by_plan = array();
		foreach ( FW_Membership_Memberships::counts_by_plan() as $pid => $count ) {
			$p = FW_Membership_Plans::get( $pid );
			if ( $p ) {
				$by_plan[ $p['slug'] ] = (int) $count;
			}
		}
		return array(
			'mrr'             => FW_Membership_Reports::money_list( $mrr['total'] ),
			'mrr_by_plan'     => $mrr_plans,
			'paying_members'  => count( array_unique( wp_list_pluck( FW_Membership_Reports::active_at( current_time( 'mysql' ), true ), 'user_id' ) ) ),
			'lifetime_value'  => FW_Membership_Reports::ltv(),
			'trials'          => FW_Membership_Reports::trials(),
			'members_by_plan' => $by_plan,
			'months'          => $months,
		);
	}

endif;
