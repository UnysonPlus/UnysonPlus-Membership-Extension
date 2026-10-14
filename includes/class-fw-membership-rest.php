<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * REST API — namespace fw-membership/v1.
 *
 * Public (no personal data; for pricing tables and headless front ends):
 *   GET  /plans                      open plans with prices and length
 *   GET  /plans/{id}
 *   GET  /access?post={id}           is it protected, may the current person see it
 * The logged-in member:
 *   GET  /me                         their memberships, plans and profile fields
 * Membership managers (FW_Membership_Service::capability()):
 *   GET  /members                    paged (X-WP-Total, X-WP-TotalPages); plan, status, search
 *   GET  /members/{user_id}          memberships + history + profile
 *   POST /memberships                grant: user_id or email, plan (id or slug), ends?, note?, notify?
 *   GET  /memberships/{id}
 *   POST /memberships/{id}/{op}      op = extend (until?) | cancel (now?) | expire | reactivate |
 *                                    activate | change-plan (plan)
 *   GET  /rules
 *
 * Every change goes through FW_Membership_Service, so history and events stay whole.
 * Errors are WP_Errors with real HTTP status codes.
 */
class FW_Membership_REST {

	const NS = 'fw-membership/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		$manage = array( __CLASS__, 'can_manage' );
		$public = '__return_true';

		register_rest_route( self::NS, '/plans', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'plans' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/plans/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'plan' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/access', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'access' ),
			'permission_callback' => $public,
			'args'                => array( 'post' => array( 'required' => true, 'type' => 'integer' ) ),
		) );
		register_rest_route( self::NS, '/me', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'me' ), 'permission_callback' => 'is_user_logged_in' ) );
		register_rest_route( self::NS, '/members', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'members' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NS, '/members/(?P<user_id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'member' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NS, '/memberships', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'grant' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NS, '/memberships/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'membership' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NS, '/memberships/(?P<id>\d+)/(?P<op>extend|cancel|expire|reactivate|activate|change-plan)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'operate' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NS, '/rules', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rules' ), 'permission_callback' => $manage ) );
	}

	/** @return bool */
	public static function can_manage() {
		return current_user_can( FW_Membership_Service::capability() );
	}

	/* ---------------------------------------------------------------------- *
	 * Shapes
	 * ---------------------------------------------------------------------- */

	public static function plan_out( array $p ) {
		return array(
			'id'          => $p['id'],
			'slug'        => $p['slug'],
			'name'        => $p['name'],
			'description' => $p['description'],
			'status'      => $p['status'],
			'priority'    => $p['priority'],
			'includes'    => $p['parent_id'] ? $p['parent_id'] : null,
			'length'      => FW_Membership_Plans::duration_label( $p ),
			'prices'      => array_map( function ( $pr ) {
				return array(
					'id'         => $pr['id'],
					'label'      => $pr['label'],
					'amount'     => $pr['amount'],
					'currency'   => $pr['currency'],
					'billing'    => $pr['billing'],
					'every'      => $pr['interval_count'],
					'trial_days' => $pr['trial_days'],
					'setup_fee'  => $pr['setup_fee'],
					'text'       => FW_Membership_Plans::price_label( $pr ),
				);
			}, $p['prices'] ),
		);
	}

	public static function membership_out( array $m ) {
		$plan = FW_Membership_Plans::get( $m['plan_id'] );
		return array(
			'id'         => $m['id'],
			'user_id'    => $m['user_id'],
			'plan'       => $plan ? array( 'id' => $plan['id'], 'slug' => $plan['slug'], 'name' => $plan['name'] ) : null,
			'status'     => $m['status'],
			'source'     => $m['source'],
			'started_at' => self::date( $m['started_at'] ),
			'ends_at'    => self::date( $m['ends_at'] ),
			'cancelled'  => self::date( $m['cancelled_at'] ),
			'gives_access' => in_array( $m['status'], FW_Membership_Memberships::ACCESS_STATUSES, true ),
		);
	}

	private static function date( $mysql ) {
		return $mysql ? mysql_to_rfc3339( (string) $mysql ) : null;
	}

	private static function profile_out( $user_id, array $fields ) {
		$out = array();
		foreach ( $fields as $key => $f ) {
			$out[ $key ] = FW_Membership_Profile::get( $user_id, $key );
		}
		return $out;
	}

	private static function plan_ref( $ref ) {
		if ( is_numeric( $ref ) ) {
			return FW_Membership_Plans::get( (int) $ref );
		}
		return FW_Membership_Plans::get_by_slug( sanitize_title( (string) $ref ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Public
	 * ---------------------------------------------------------------------- */

	public static function plans() {
		return rest_ensure_response( array_values( array_map( array( __CLASS__, 'plan_out' ), array_filter( FW_Membership_Plans::all( 'active' ), function ( $p ) { return ! FW_Membership_Plans::is_item( $p ); } ) ) ) );
	}

	public static function plan( WP_REST_Request $r ) {
		$p = FW_Membership_Plans::get( (int) $r['id'] );
		if ( ! $p || ( 'active' !== $p['status'] && ! self::can_manage() ) ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Plan not found.', 'fw' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::plan_out( $p ) );
	}

	public static function access( WP_REST_Request $r ) {
		$post = get_post( (int) $r['post'] );
		if ( ! $post || ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Not found.', 'fw' ), array( 'status' => 404 ) );
		}
		$denied = FW_Membership_Access::denied_for_post( $post );
		$out    = array(
			'post'      => $post->ID,
			'protected' => FW_Membership_Access::is_protected( $post ),
			'can_view'  => null === $denied,
		);
		if ( $denied ) {
			$out['denied'] = array(
				'action' => $denied['rule']['denied']['action'],
				'plans'  => array_values( array_filter( array_map( function ( $id ) {
					$p = FW_Membership_Plans::get( $id );
					return $p ? $p['slug'] : null;
				}, $denied['rule']['who']['plans'] ) ) ),
				'login_needed' => ! is_user_logged_in(),
			);
		}
		return rest_ensure_response( $out );
	}

	public static function me() {
		$uid = get_current_user_id();
		return rest_ensure_response( array(
			'user_id'     => $uid,
			'plans'       => array_values( array_filter( array_map( function ( $id ) {
				$p = FW_Membership_Plans::get( $id );
				return $p ? $p['slug'] : null;
			}, FW_Membership_Service::user_plans( $uid ) ) ) ),
			'memberships' => array_map( array( __CLASS__, 'membership_out' ), FW_Membership_Memberships::query( array( 'user_id' => $uid, 'per_page' => 100 ) ) ),
			'profile'     => self::profile_out( $uid, FW_Membership_Profile::fields_for_user( $uid ) ),
		) );
	}

	/* ---------------------------------------------------------------------- *
	 * Managers
	 * ---------------------------------------------------------------------- */

	public static function members( WP_REST_Request $r ) {
		$per  = max( 1, min( 100, (int) ( $r['per_page'] ?? 20 ) ) );
		$page = max( 1, (int) ( $r['page'] ?? 1 ) );
		$plan = $r['plan'] ? self::plan_ref( $r['plan'] ) : null;
		$args = array(
			'plan_id'  => $plan ? $plan['id'] : 0,
			'status'   => $r['status'] ? ( 'access' === $r['status'] ? 'access' : array( sanitize_key( $r['status'] ) ) ) : '',
			'search'   => (string) ( $r['search'] ?? '' ),
			'per_page' => $per,
			'page'     => $page,
		);
		$rows  = FW_Membership_Memberships::query( $args );
		$total = FW_Membership_Memberships::count( $args );
		$out   = array();
		foreach ( $rows as $m ) {
			$u     = get_userdata( $m['user_id'] );
			$item  = self::membership_out( $m );
			$item['member'] = $u ? array( 'id' => $u->ID, 'name' => $u->display_name, 'email' => $u->user_email ) : null;
			$out[] = $item;
		}
		$res = rest_ensure_response( $out );
		$res->header( 'X-WP-Total', (string) $total );
		$res->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per ) ) );
		return $res;
	}

	public static function member( WP_REST_Request $r ) {
		$u = get_userdata( (int) $r['user_id'] );
		if ( ! $u ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Member not found.', 'fw' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array(
			'id'          => $u->ID,
			'name'        => $u->display_name,
			'email'       => $u->user_email,
			'state'       => FW_Membership_Verification::state( $u->ID ),
			'memberships' => array_map( array( __CLASS__, 'membership_out' ), FW_Membership_Memberships::query( array( 'user_id' => $u->ID, 'per_page' => 100 ) ) ),
			'history'     => array_map( function ( $h ) {
				return array( 'type' => $h->type, 'membership_id' => (int) $h->membership_id, 'note' => $h->note, 'at' => mysql_to_rfc3339( $h->created_at ) );
			}, FW_Membership_Memberships::history( array( 'user_id' => $u->ID, 'limit' => 100 ) ) ),
			'profile'     => self::profile_out( $u->ID, FW_Membership_Profile::fields() ),
		) );
	}

	public static function grant( WP_REST_Request $r ) {
		$user = $r['user_id'] ? get_userdata( (int) $r['user_id'] ) : ( $r['email'] ? get_user_by( 'email', sanitize_email( (string) $r['email'] ) ) : null );
		if ( ! $user ) {
			return new WP_Error( 'fw_mbr_no_user', __( 'No account matches that user_id or email.', 'fw' ), array( 'status' => 404 ) );
		}
		$plan = self::plan_ref( $r['plan'] );
		if ( ! $plan ) {
			return new WP_Error( 'fw_mbr_no_plan', __( 'That plan does not exist.', 'fw' ), array( 'status' => 400 ) );
		}
		$res = FW_Membership_Service::grant( $user->ID, $plan['id'], array(
			'source' => 'manual',
			'ends'   => (string) ( $r['ends'] ?? '' ),
			'note'   => sanitize_text_field( (string) ( $r['note'] ?? '' ) ),
			'notify' => (bool) $r['notify'],
		) );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 'fw_mbr_already' === $res->get_error_code() ? 409 : 400 ) );
			return $res;
		}
		$out = rest_ensure_response( self::membership_out( FW_Membership_Memberships::get( $res ) ) );
		$out->set_status( 201 );
		return $out;
	}

	public static function membership( WP_REST_Request $r ) {
		$m = FW_Membership_Memberships::get( (int) $r['id'] );
		return $m ? rest_ensure_response( self::membership_out( $m ) ) : new WP_Error( 'fw_mbr_not_found', __( 'Membership not found.', 'fw' ), array( 'status' => 404 ) );
	}

	public static function operate( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! FW_Membership_Memberships::get( $id ) ) {
			return new WP_Error( 'fw_mbr_not_found', __( 'Membership not found.', 'fw' ), array( 'status' => 404 ) );
		}
		switch ( $r['op'] ) {
			case 'extend':
				$res = FW_Membership_Service::extend( $id, (string) ( $r['until'] ?? '' ) );
				break;
			case 'cancel':
				$res = FW_Membership_Service::cancel( $id, (bool) $r['now'] );
				break;
			case 'expire':
				$res = FW_Membership_Service::expire( $id, __( 'Ended through the REST API.', 'fw' ) );
				break;
			case 'reactivate':
				$res = FW_Membership_Service::reactivate( $id );
				break;
			case 'activate':
				$res = FW_Membership_Service::activate( $id );
				break;
			default: // change-plan
				$plan = self::plan_ref( $r['plan'] );
				$res  = $plan ? FW_Membership_Service::change_plan( $id, $plan['id'] ) : new WP_Error( 'fw_mbr_no_plan', __( 'That plan does not exist.', 'fw' ) );
		}
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 400 ) );
			return $res;
		}
		return rest_ensure_response( self::membership_out( FW_Membership_Memberships::get( $id ) ) );
	}

	public static function rules() {
		$out = array();
		foreach ( FW_Membership_Rules::all() as $rule ) {
			$out[] = array(
				'id'       => $rule['id'],
				'name'     => $rule['name'],
				'status'   => $rule['status'],
				'priority' => $rule['priority'],
				'who'      => $rule['who'],
				'denied'   => $rule['denied'],
				'targets'  => array_map( function ( $t ) {
					return array( 'mode' => $t['mode'], 'type' => $t['type'], 'sub_type' => $t['sub_type'], 'object_id' => $t['object_id'], 'pattern' => $t['pattern'] );
				}, $rule['targets'] ),
			);
		}
		return rest_ensure_response( $out );
	}
}
