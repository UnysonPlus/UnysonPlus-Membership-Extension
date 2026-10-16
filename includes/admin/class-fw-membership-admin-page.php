<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Unyson+ → Membership screen. Same conventions as the CRM's admin page:
 *  - add_submenu_page() under the shared `fw-extensions` parent, position declared
 *    through the `fw_unysonplus_admin_submenu_order` filter;
 *  - every POST/GET action runs on `load-{$hook_suffix}`, before any output, and ends
 *    in a redirect (post/redirect/get), with the notice carried in a transient;
 *  - the frame (sidebar + top bar) is FW_Membership_App; each area renders inside it.
 */
class FW_Membership_Admin_Page {

	const PARENT_SLUG      = 'fw-extensions';
	const PAGE_SLUG        = 'fw-membership';
	const NONCE            = 'fw_mbr_action';
	const TRANSIENT_NOTICE = 'fw_ext_membership_notice_';

	/** @var FW_Extension_Membership */
	private $ext;

	/** @var string|null */
	private $hook_suffix = null;

	/**
	 * @param FW_Extension_Membership $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;

		add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 30 );
		add_filter( 'fw_unysonplus_admin_submenu_order', array( $this, '_filter_submenu_order' ) );
	}

	/** @internal */
	public function _action_admin_menu() {
		if ( ! current_user_can( FW_Membership_Service::capability() ) ) {
			return;
		}

		// The Unyson+ menu is for administrators. Someone given Membership through the
		// unysonplus_membership_capability filter (a membership manager role) but not
		// manage_options gets Membership as a menu of its own, as the CRM does.
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->hook_suffix = add_menu_page(
				__( 'Membership', 'fw' ),
				__( 'Membership', 'fw' ),
				FW_Membership_Service::capability(),
				self::PAGE_SLUG,
				array( $this, 'render_page' ),
				'dashicons-awards',
				4
			);
		} else {
			$this->hook_suffix = add_submenu_page(
				self::PARENT_SLUG,
				__( 'Membership', 'fw' ),
				__( 'Membership', 'fw' ),
				FW_Membership_Service::capability(),
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		if ( $this->hook_suffix ) {
			FW_Membership_App::init( $this->hook_suffix );
			add_action( 'load-' . $this->hook_suffix, array( $this, '_action_load' ) );
			add_action( 'admin_enqueue_scripts', array( $this, '_action_enqueue' ) );
		}
	}

	/**
	 * @internal We declare where we want to sit; the Post Types extension owns the sort.
	 *
	 * @param array $order
	 *
	 * @return array
	 */
	public function _filter_submenu_order( $order ) {
		if ( ! in_array( self::PAGE_SLUG, (array) $order, true ) ) {
			$order[] = self::PAGE_SLUG;
		}
		return $order;
	}

	/**
	 * @internal
	 *
	 * @param string $hook
	 */
	public function _action_enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}
		$ver = $this->ext->manifest->get_version();
		wp_enqueue_style( 'fw-ext-membership-app', $this->ext->get_uri() . '/static/css/app.css', array( 'dashicons' ), $ver );
		wp_enqueue_script( 'fw-ext-membership-app', $this->ext->get_uri() . '/static/js/app.js', array(), $ver, true );
	}

	/* ---------------------------------------------------------------------- *
	 * URLs
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $tab
	 * @param array  $args
	 *
	 * @return string
	 */
	public static function get_page_url( $tab = '', array $args = array() ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		if ( '' !== $tab ) {
			$url = add_query_arg( 'tab', $tab, $url );
		}
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * A nonced GET action link.
	 *
	 * @param string $action
	 * @param array  $args
	 * @param string $tab
	 *
	 * @return string
	 */
	public static function action_url( $action, array $args, $tab ) {
		return wp_nonce_url( self::get_page_url( $tab, array_merge( $args, array( 'fw_mbr_action' => $action ) ) ), self::NONCE );
	}

	/**
	 * A hidden-fields block for a POST form.
	 *
	 * @param string $action
	 */
	public static function form_fields( $action ) {
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="fw_mbr_action" value="%s" />', esc_attr( $action ) );
	}

	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'home'; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $tab, FW_Membership_App::views(), true ) ? $tab : 'home';
	}

	/* ---------------------------------------------------------------------- *
	 * Request handling — all of it before any output
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public function _action_load() {
		if ( ! current_user_can( FW_Membership_Service::capability() ) ) {
			return;
		}

		$action = '';
		if ( isset( $_POST['fw_mbr_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$action = sanitize_key( wp_unslash( $_POST['fw_mbr_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( isset( $_GET['fw_mbr_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$action = sanitize_key( wp_unslash( $_GET['fw_mbr_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( '' === $action ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$back = null;
		switch ( $action ) {
			case 'save_plan':
			case 'delete_plan':
			case 'archive_plan':
			case 'restore_plan':
				$back = FW_Membership_Admin_Plans::handle( $action );
				break;

			case 'add_member':
			case 'grant':
			case 'extend':
			case 'cancel':
			case 'expire':
			case 'reactivate':
			case 'change_plan':
				$back = FW_Membership_Admin_Members::handle( $action );
				break;

			case 'save_rule':
			case 'delete_rule':
			case 'toggle_rule':
				$back = FW_Membership_Admin_Rules::handle( $action );
				break;

			case 'save_modules':
				$back = $this->handle_save_modules();
				break;

			case 'save_profile_fields':
			case 'save_member_profile':
			case 'save_emails':
			case 'test_email':
			case 'save_settings':
			case 'remove_data':
			case 'approve_member':
			case 'resend_verify':
			case 'mark_verified':
				$back = FW_Membership_Admin_Settings::handle( $action );
				break;

			default:
				/**
				 * Lets a module handle its own admin actions. Return an array of query
				 * args to redirect to, or null to leave the action unhandled.
				 */
				$back = apply_filters( 'unysonplus_membership_admin_action', null, $action );
		}

		wp_safe_redirect( self::get_page_url( '', is_array( $back ) ? $back : array( 'tab' => $this->current_tab() ) ) );
		exit;
	}

	private function handle_save_modules() {
		$on = isset( $_POST['modules'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['modules'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( FW_Membership_Modules::registry() as $slug => $m ) {
			if ( ! empty( $m['auto'] ) || ! FW_Membership_Modules::is_available( $slug ) ) {
				continue;
			}
			FW_Membership_Modules::set( $slug, in_array( $slug, $on, true ) );
		}
		self::flash( 'success', __( 'Modules saved.', 'fw' ) );

		return array( 'tab' => 'modules' );
	}

	/**
	 * Hand a notice across the next redirect.
	 *
	 * @param string $type success|error|warning|info
	 * @param string $message
	 */
	public static function flash( $type, $message ) {
		set_transient( self::TRANSIENT_NOTICE . get_current_user_id(), array(
			'type'    => $type,
			'message' => $message,
		), MINUTE_IN_SECONDS );
	}

	/**
	 * @param WP_Error|mixed $result
	 * @param string         $success
	 *
	 * @return bool Whether it succeeded.
	 */
	public static function flash_result( $result, $success ) {
		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			return false;
		}
		self::flash( 'success', $success );
		return true;
	}

	/* ---------------------------------------------------------------------- *
	 * Render
	 * ---------------------------------------------------------------------- */

	public function render_page() {
		if ( ! current_user_can( FW_Membership_Service::capability() ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- read-only routing.
		$tab    = $this->current_tab();
		$base   = self::get_page_url();
		$item   = FW_Membership_App::item( $tab );
		$title  = $item ? $item['label'] : __( 'Search', 'fw' );
		$crumbs = array( array( $title ) );
		$member = isset( $_GET['member'] ) ? (int) $_GET['member'] : 0;
		$plan   = isset( $_GET['plan'] ) ? (int) $_GET['plan'] : 0;
		$rule   = isset( $_GET['rule'] ) ? (int) $_GET['rule'] : 0;
		$is_new = ! empty( $_GET['new'] );
		// phpcs:enable

		if ( 'members' === $tab && ( $member || $is_new ) ) {
			$name = __( 'Add a member', 'fw' );
			if ( $member && ( $u = get_userdata( $member ) ) ) {
				$name = $u->display_name ? $u->display_name : $u->user_email;
			}
			$crumbs = array( array( $title, add_query_arg( 'tab', 'members', $base ) ), array( $name ) );
		} elseif ( 'plans' === $tab && ( $plan || $is_new ) ) {
			$name = __( 'New plan', 'fw' );
			if ( $plan && ( $p = FW_Membership_Plans::get( $plan ) ) ) {
				$name = $p['name'];
			}
			$crumbs = array( array( $title, add_query_arg( 'tab', 'plans', $base ) ), array( $name ) );
		} elseif ( 'rules' === $tab && ( $rule || $is_new ) ) {
			$name = __( 'New access rule', 'fw' );
			if ( $rule && ( $r = FW_Membership_Rules::get( $rule ) ) ) {
				$name = $r['name'];
			}
			$crumbs = array( array( $title, add_query_arg( 'tab', 'rules', $base ) ), array( $name ) );
		}

		FW_Membership_App::open( $tab, $crumbs );
		$this->render_notice();

		if ( ! FW_Membership_App::can_view( $tab ) ) {
			FW_Membership_App::empty_state( 'dashicons-lock', __( 'For membership managers', 'fw' ), __( 'This part of Membership is open to its managers and administrators. Ask one of them if you need something here.', 'fw' ), array( array( __( 'Back to Home', 'fw' ), add_query_arg( 'tab', 'home', $base ) ) ) );
			FW_Membership_App::close();
			return;
		}

		switch ( $tab ) {
			case 'home':
				FW_Membership_Dashboard::render();
				break;
			case 'members':
				if ( $member ) {
					FW_Membership_Admin_Members::render_member( $member );
				} elseif ( $is_new ) {
					FW_Membership_Admin_Members::render_new();
				} else {
					FW_Membership_Admin_Members::render_list();
				}
				break;
			case 'plans':
				if ( $plan || $is_new ) {
					FW_Membership_Admin_Plans::render_edit( $plan );
				} else {
					FW_Membership_Admin_Plans::render_list();
				}
				break;
			case 'rules':
				if ( $rule || $is_new ) {
					FW_Membership_Admin_Rules::render_edit( $rule );
				} else {
					FW_Membership_Admin_Rules::render_list();
				}
				break;
			case 'modules':
				$this->render_modules();
				break;
			case 'emails':
				FW_Membership_Admin_Settings::render_emails();
				break;
			case 'fields':
				FW_Membership_Admin_Settings::render_profile_fields();
				break;
			case 'settings':
				FW_Membership_Admin_Settings::render_settings();
				break;
			default:
				if ( $item && ! empty( $item['soon'] ) ) {
					FW_Membership_App::render_soon( $tab );
				} else {
					/**
					 * Renders a Membership view added through the `unysonplus_membership_nav` filter.
					 *
					 * @param string $tab The view slug.
					 */
					do_action( 'unysonplus_membership_render_view', $tab );
				}
		}

		FW_Membership_App::close();
	}

	private function render_notice() {
		$key    = self::TRANSIENT_NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $notice['type'] ),
			wp_kses_post( $notice['message'] )
		);
	}

	private function render_modules() {
		$reg = FW_Membership_Modules::registry();
		?>
		<p class="fw-mbr-status-line"><?php esc_html_e( 'The core (plans, members, access rules and the member area) is always on. Switch on the modules your site needs; a module that is off loads no code and adds no menus.', 'fw' ); ?></p>
		<form method="post" class="fw-mbr-modules-form">
			<?php self::form_fields( 'save_modules' ); ?>
			<ul class="fw-mbr-modules" role="list">
				<?php foreach ( $reg as $slug => $m ) : ?>
					<?php
					$available = FW_Membership_Modules::is_available( $slug );
					$met       = ! isset( $m['requires'] ) || call_user_func( $m['requires'] );
					$on        = FW_Membership_Modules::is_on( $slug );
					$auto      = ! empty( $m['auto'] );
					$id        = 'fw-mbr-mod-' . $slug;
					?>
					<li class="fw-mbr-module<?php echo $on ? ' is-on' : ''; ?><?php echo ! $available ? ' is-soon' : ''; ?>">
						<span class="dashicons <?php echo esc_attr( $m['icon'] ); ?>" aria-hidden="true"></span>
						<div class="fw-mbr-module__text">
							<label for="<?php echo esc_attr( $id ); ?>" class="fw-mbr-module__name"><?php echo esc_html( $m['label'] ); ?></label>
							<p><?php echo esc_html( $m['description'] ); ?></p>
							<?php if ( ! empty( $m['requires_text'] ) ) : ?>
								<p class="fw-mbr-muted"><?php echo esc_html( $m['requires_text'] ); ?></p>
							<?php endif; ?>
						</div>
						<div class="fw-mbr-module__switch">
							<?php if ( ! $available ) : ?>
								<span class="fw-mbr-module__soon"><?php echo esc_html( sprintf( __( 'Coming in phase %d', 'fw' ), (int) $m['phase'] ) ); ?></span>
							<?php elseif ( $auto ) : ?>
								<span class="fw-mbr-module__soon"><?php echo $on ? esc_html__( 'On', 'fw' ) : esc_html__( 'Off', 'fw' ); ?></span>
							<?php else : ?>
								<input type="checkbox" class="fw-mbr-toggle" role="switch" id="<?php echo esc_attr( $id ); ?>" name="modules[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $on ); ?> <?php disabled( ! $met ); ?> />
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save modules', 'fw' ); ?></button></p>
		</form>
		<?php
	}
}
