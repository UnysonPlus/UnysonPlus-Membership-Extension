<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Move a Membership set-up between sites (Membership → Import / Export → Set-up file).
 *
 * The file is JSON: every plan with its prices, every access rule with what it protects,
 * and the Membership settings (member pages, emails, profile fields, modules, payments,
 * paywall, retention, directory, cards, CRM connection). Members are not in it — they
 * travel in the Members CSV.
 *
 * Ids differ between sites, so the file names things instead: plans by slug, pages and
 * posts by post type + path, terms by taxonomy + slug. Importing it back on the same site
 * changes nothing. Payment secret keys are never written; importing keeps the site's own.
 */
class FW_Membership_Setup {

	const FORMAT  = 'unysonplus-membership-setup';
	const VERSION = 1;

	/** Settings options carried in the file: file key => option name. */
	const OPTIONS = array(
		'settings'       => 'fw_ext_membership_settings',
		'emails'         => 'fw_ext_membership_emails',
		'profile_fields' => 'fw_ext_membership_profile_fields',
		'modules'        => 'fw_ext_membership_modules',
		'payments'       => 'fw_ext_membership_payments',
		'paywall'        => 'fw_ext_membership_paywall',
		'retention'      => 'fw_ext_membership_retention',
		'directory'      => 'fw_ext_membership_directory',
		'cards'          => 'fw_ext_membership_cards',
		'crm'            => 'fw_ext_membership_crm',
	);

	/** Page-id settings, by option: file key => [ setting keys ]. */
	const PAGE_KEYS = array(
		'settings' => array( 'account_page', 'login_page', 'join_page' ),
		'payments' => array( 'checkout_page' ),
	);

	/** Gateway setting names that hold secrets: never exported, never overwritten. */
	const SECRET = '/secret|key|token|password|signing/i';

	public static function init() {
		add_filter( 'unysonplus_membership_admin_action', array( __CLASS__, '_action' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------- *
	 * Export
	 * ---------------------------------------------------------------------- */

	/** @return array The whole set-up, ready for wp_json_encode(). */
	public static function export() {
		$plans  = FW_Membership_Plans::all();
		$slug   = function ( $id ) use ( $plans ) {
			return isset( $plans[ (int) $id ] ) ? $plans[ (int) $id ]['slug'] : '';
		};
		$out = array(
			'format'      => self::FORMAT,
			'version'     => self::VERSION,
			'exported_at' => gmdate( 'c' ),
			'plans'       => array(),
			'rules'       => array(),
			'options'     => array(),
		);

		foreach ( $plans as $p ) {
			$settings = (array) $p['settings'];
			if ( isset( $settings['switch_to'] ) ) {
				$settings['switch_to'] = array_values( array_filter( array_map( $slug, (array) $settings['switch_to'] ) ) );
			}
			$prices = array();
			foreach ( $p['prices'] as $pr ) {
				$prices[] = array(
					'label'          => $pr['label'],
					'amount'         => (float) $pr['amount'],
					'currency'       => $pr['currency'],
					'billing'        => $pr['billing'],
					'interval_count' => (int) $pr['interval_count'],
					'trial_days'     => (int) $pr['trial_days'],
					'setup_fee'      => (float) $pr['setup_fee'],
				);
			}
			$out['plans'][] = array(
				'slug'           => $p['slug'],
				'name'           => $p['name'],
				'description'    => (string) $p['description'],
				'status'         => $p['status'],
				'priority'       => (int) $p['priority'],
				'includes'       => $p['parent_id'] ? $slug( $p['parent_id'] ) : '',
				'duration_value' => (int) $p['duration_value'],
				'duration_unit'  => (string) $p['duration_unit'],
				'fixed_end_date' => $p['fixed_end_date'] ? substr( (string) $p['fixed_end_date'], 0, 10 ) : '',
				'signup_limit'   => (int) $p['signup_limit'],
				'settings'       => $settings,
				'prices'         => $prices,
			);
		}

		foreach ( FW_Membership_Rules::all() as $r ) {
			$targets = array();
			foreach ( $r['targets'] as $t ) {
				$targets[] = array(
					'mode'     => $t['mode'],
					'type'     => $t['type'],
					'sub_type' => (string) $t['sub_type'],
					'object'   => self::object_ref( $t['type'], (int) $t['object_id'], (string) $t['sub_type'] ),
					'pattern'  => (string) $t['pattern'],
				);
			}
			$who          = $r['who'];
			$who['plans'] = array_values( array_filter( array_map( $slug, $who['plans'] ) ) );
			$out['rules'][] = array(
				'name'      => $r['name'],
				'status'    => $r['status'],
				'priority'  => (int) $r['priority'],
				'drip_days' => (int) $r['drip_days'],
				'drip_date' => $r['drip_date'] ? substr( $r['drip_date'], 0, 10 ) : '',
				'who'       => $who,
				'denied'    => $r['denied'],
				'targets'   => $targets,
			);
		}

		foreach ( self::OPTIONS as $key => $option ) {
			$value = get_option( $option, null );
			if ( null === $value || false === $value ) {
				continue;
			}
			foreach ( self::PAGE_KEYS[ $key ] ?? array() as $pk ) {
				if ( is_array( $value ) && isset( $value[ $pk ] ) ) {
					$value[ $pk ] = self::object_ref( 'post', (int) $value[ $pk ] );
				}
			}
			if ( 'payments' === $key && is_array( $value ) ) {
				$value = self::strip_secrets( $value );
			}
			$out['options'][ $key ] = $value;
		}

		return $out;
	}

	/**
	 * A post or term named so another site can find it: "page:about/team", "category:news".
	 *
	 * @return string '' when there is nothing to name.
	 */
	private static function object_ref( $type, $id, $sub_type = '' ) {
		if ( ! $id ) {
			return '';
		}
		if ( 'term' === $type ) {
			$term = get_term( $id );
			return $term && ! is_wp_error( $term ) ? $term->taxonomy . ':' . $term->slug : '';
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return '';
		}
		$path = get_page_uri( $post );
		return $post->post_type . ':' . ( $path ? $path : $post->post_name );
	}

	/** @return int The id on this site, or 0. */
	private static function resolve_ref( $type, $ref ) {
		$ref = (string) $ref;
		if ( '' === $ref || false === strpos( $ref, ':' ) ) {
			return 0;
		}
		list( $kind, $name ) = explode( ':', $ref, 2 );
		if ( 'term' === $type ) {
			$term = get_term_by( 'slug', $name, $kind );
			return $term ? (int) $term->term_id : 0;
		}
		$post = get_page_by_path( $name, OBJECT, $kind );
		return $post ? (int) $post->ID : 0;
	}

	private static function strip_secrets( array $value ) {
		foreach ( (array) ( $value['gateways'] ?? array() ) as $g => $conf ) {
			foreach ( (array) $conf as $k => $v ) {
				if ( preg_match( self::SECRET, (string) $k ) ) {
					unset( $value['gateways'][ $g ][ $k ] );
				}
			}
		}
		return $value;
	}

	/* ---------------------------------------------------------------------- *
	 * Import
	 * ---------------------------------------------------------------------- */

	/**
	 * Apply a set-up file. Plans are matched by slug and rules by name: a match is updated,
	 * anything else is added. Nothing on the site is deleted.
	 *
	 * @param array $data    The decoded file.
	 * @param bool  $dry_run Report only.
	 *
	 * @return array|WP_Error { plans_added, plans_updated, rules_added, rules_updated, options, notes[] }
	 */
	public static function import( array $data, $dry_run = false ) {
		if ( self::FORMAT !== ( $data['format'] ?? '' ) ) {
			return new WP_Error( 'fw_mbr_setup_format', __( 'This is not a Membership set-up file.', 'fw' ) );
		}
		$res = array( 'plans_added' => 0, 'plans_updated' => 0, 'rules_added' => 0, 'rules_updated' => 0, 'options' => 0, 'notes' => array() );

		// Plans, in two passes: save them, then link "includes" and "can switch to" by slug.
		$ids = array();
		foreach ( (array) ( $data['plans'] ?? array() ) as $p ) {
			$slug = sanitize_title( (string) ( $p['slug'] ?? '' ) );
			if ( '' === $slug ) {
				continue;
			}
			$have = FW_Membership_Plans::get_by_slug( $slug );
			$have ? $res['plans_updated']++ : $res['plans_added']++;
			if ( $dry_run ) {
				$ids[ $slug ] = $have ? (int) $have['id'] : 0;
				continue;
			}
			$prices = array();
			foreach ( (array) ( $p['prices'] ?? array() ) as $pr ) {
				// An unchanged price keeps its row (members pay with it); see Plans::replace_prices().
				foreach ( $have ? $have['prices'] : array() as $cur ) {
					if ( (string) $cur['billing'] === (string) ( $pr['billing'] ?? '' ) && (float) $cur['amount'] === (float) ( $pr['amount'] ?? 0 )
						&& strtoupper( (string) $cur['currency'] ) === strtoupper( (string) ( $pr['currency'] ?? '' ) ) && (int) $cur['interval_count'] === (int) ( $pr['interval_count'] ?? 1 )
						&& (int) $cur['trial_days'] === (int) ( $pr['trial_days'] ?? 0 ) && (float) $cur['setup_fee'] === (float) ( $pr['setup_fee'] ?? 0 ) ) {
						$pr['id'] = (int) $cur['id'];
						break;
					}
				}
				$prices[] = $pr;
			}
			$settings = (array) ( $p['settings'] ?? array() );
			unset( $settings['switch_to'] ); // linked in the second pass
			$id = FW_Membership_Plans::save( array(
				'name'           => (string) ( $p['name'] ?? $slug ),
				'slug'           => $slug,
				'description'    => (string) ( $p['description'] ?? '' ),
				'status'         => (string) ( $p['status'] ?? 'active' ),
				'priority'       => (int) ( $p['priority'] ?? 0 ),
				'parent_id'      => $have ? (int) $have['parent_id'] : 0,
				'duration_value' => (int) ( $p['duration_value'] ?? 0 ),
				'duration_unit'  => (string) ( $p['duration_unit'] ?? '' ),
				'fixed_end_date' => (string) ( $p['fixed_end_date'] ?? '' ),
				'signup_limit'   => (int) ( $p['signup_limit'] ?? 0 ),
				'settings'       => $settings,
			), $prices, $have ? (int) $have['id'] : 0 );
			if ( is_wp_error( $id ) ) {
				$res['notes'][] = sprintf( '%s: %s', $slug, $id->get_error_message() );
				continue;
			}
			$ids[ $slug ] = (int) $id;
		}
		$plan_id = function ( $slug ) use ( &$ids ) {
			if ( isset( $ids[ $slug ] ) ) {
				return $ids[ $slug ];
			}
			$p = FW_Membership_Plans::get_by_slug( (string) $slug );
			return $p ? (int) $p['id'] : 0;
		};
		if ( ! $dry_run ) {
			foreach ( (array) ( $data['plans'] ?? array() ) as $p ) {
				$id = $ids[ sanitize_title( (string) ( $p['slug'] ?? '' ) ) ] ?? 0;
				$me = $id ? FW_Membership_Plans::get( $id ) : null;
				if ( ! $me ) {
					continue;
				}
				$settings              = (array) ( $p['settings'] ?? array() );
				if ( isset( $settings['switch_to'] ) ) {
					$settings['switch_to'] = array_values( array_filter( array_map( $plan_id, (array) $settings['switch_to'] ) ) );
				}
				$me['parent_id']       = ! empty( $p['includes'] ) ? $plan_id( $p['includes'] ) : 0;
				$me['settings']        = $settings;
				$r = FW_Membership_Plans::save( $me, $me['prices'], $id );
				if ( is_wp_error( $r ) ) {
					$res['notes'][] = sprintf( '%s: %s', $me['slug'], $r->get_error_message() );
				}
			}
		}

		// Access rules, matched by name.
		$by_name = array();
		foreach ( FW_Membership_Rules::all() as $r ) {
			$by_name[ $r['name'] ] = (int) $r['id'];
		}
		foreach ( (array) ( $data['rules'] ?? array() ) as $r ) {
			$name = (string) ( $r['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$targets = array();
			foreach ( (array) ( $r['targets'] ?? array() ) as $t ) {
				$type = (string) ( $t['type'] ?? '' );
				$oid  = 0;
				if ( in_array( $type, array( 'post', 'children', 'term' ), true ) ) {
					$oid = self::resolve_ref( 'term' === $type ? 'term' : 'post', $t['object'] ?? '' );
					if ( ! $oid ) {
						/* translators: 1: rule name, 2: what it protected, e.g. "page:about". */
						$res['notes'][] = sprintf( __( 'Rule "%1$s": %2$s is not on this site, so it was left out.', 'fw' ), $name, (string) ( $t['object'] ?? '' ) );
						continue;
					}
				}
				$targets[] = array( 'mode' => $t['mode'] ?? 'include', 'type' => $type, 'sub_type' => $t['sub_type'] ?? '', 'object_id' => $oid, 'pattern' => $t['pattern'] ?? '' );
			}
			$who          = (array) ( $r['who'] ?? array() );
			$who['plans'] = array_values( array_filter( array_map( $plan_id, (array) ( $who['plans'] ?? array() ) ) ) );
			isset( $by_name[ $name ] ) ? $res['rules_updated']++ : $res['rules_added']++;
			if ( $dry_run ) {
				continue;
			}
			$saved = FW_Membership_Rules::save( array(
				'name'      => $name,
				'status'    => $r['status'] ?? 'active',
				'priority'  => (int) ( $r['priority'] ?? 0 ),
				'drip_days' => (int) ( $r['drip_days'] ?? 0 ),
				'drip_date' => (string) ( $r['drip_date'] ?? '' ),
				'who'       => $who,
				'denied'    => (array) ( $r['denied'] ?? array() ),
			), $targets, $by_name[ $name ] ?? 0 );
			if ( is_wp_error( $saved ) ) {
				$res['notes'][] = sprintf( 'Rule "%s": %s', $name, $saved->get_error_message() );
			}
		}

		// Settings.
		foreach ( self::OPTIONS as $key => $option ) {
			if ( ! array_key_exists( $key, (array) ( $data['options'] ?? array() ) ) ) {
				continue;
			}
			$value = $data['options'][ $key ];
			foreach ( self::PAGE_KEYS[ $key ] ?? array() as $pk ) {
				if ( is_array( $value ) && isset( $value[ $pk ] ) ) {
					$ref          = (string) $value[ $pk ];
					$value[ $pk ] = self::resolve_ref( 'post', $ref );
					if ( '' !== $ref && ! $value[ $pk ] ) {
						/* translators: %s: a page, e.g. "page:account". */
						$res['notes'][] = sprintf( __( 'Page %s is not on this site: choose it again in Settings.', 'fw' ), $ref );
					}
				}
			}
			if ( 'payments' === $key && is_array( $value ) ) {
				// Keep this site's own keys.
				$value = self::strip_secrets( $value );
				$cur   = (array) get_option( $option, array() );
				foreach ( (array) ( $cur['gateways'] ?? array() ) as $g => $conf ) {
					foreach ( (array) $conf as $k => $v ) {
						if ( preg_match( self::SECRET, (string) $k ) ) {
							$value['gateways'][ $g ][ $k ] = $v;
						}
					}
				}
			}
			$res['options']++;
			if ( ! $dry_run ) {
				update_option( $option, $value, false );
			}
		}

		if ( ! $dry_run ) {
			FW_Membership_Plans::flush();
			FW_Membership_Rules::flush();
			if ( class_exists( 'FW_Membership_Service' ) ) {
				FW_Membership_Service::flush_cache();
			}
		}
		return $res;
	}

	/* ---------------------------------------------------------------------- *
	 * Admin actions
	 * ---------------------------------------------------------------------- */

	/** @internal Import / Export → Download the set-up file, and → Import a set-up file. */
	public static function _action( $back, $action ) {
		if ( 'export_setup' === $action ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="membership-setup-' . current_time( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( self::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		if ( 'import_setup' !== $action ) {
			return $back;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page before actions run
		$up = $_FILES['setup'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $up || UPLOAD_ERR_OK !== (int) $up['error'] || ! is_uploaded_file( $up['tmp_name'] ) ) {
			FW_Membership_Admin_Page::flash( 'error', __( 'Choose a set-up file (.json).', 'fw' ) );
			return array( 'tab' => 'import' );
		}
		$data = json_decode( (string) file_get_contents( $up['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) ) {
			FW_Membership_Admin_Page::flash( 'error', __( 'That file is not valid JSON.', 'fw' ) );
			return array( 'tab' => 'import' );
		}
		$dry = ! empty( $_POST['dry_run'] );
		$res = self::import( $data, $dry );
		// phpcs:enable
		if ( is_wp_error( $res ) ) {
			FW_Membership_Admin_Page::flash( 'error', $res->get_error_message() );
			return array( 'tab' => 'import' );
		}
		$msg = sprintf(
			/* translators: 1-4: counts, 5: count of settings groups. */
			$dry ? __( 'Dry run: %1$d plans would be added and %2$d updated, %3$d access rules added and %4$d updated, %5$d groups of settings replaced.', 'fw' ) : __( 'Set-up imported: %1$d plans added, %2$d updated; %3$d access rules added, %4$d updated; %5$d groups of settings replaced.', 'fw' ),
			$res['plans_added'], $res['plans_updated'], $res['rules_added'], $res['rules_updated'], $res['options']
		);
		if ( $res['notes'] ) {
			$msg .= ' ' . implode( ' ', array_slice( $res['notes'], 0, 10 ) );
		}
		FW_Membership_Admin_Page::flash( $res['notes'] ? 'warning' : 'success', $msg );
		return array( 'tab' => 'import' );
	}
}
