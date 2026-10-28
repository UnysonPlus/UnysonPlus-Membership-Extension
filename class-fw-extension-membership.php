<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Membership.
 *
 * Plans, the membership records that say who holds which plan and in what state,
 * and (as phase 1 continues) one access engine every gate asks. Payments, drip,
 * groups, retention, courses and the CRM connection are switchable modules on top.
 *
 * Layering, the same as the CRM's (see AGENTS.md):
 *
 *   Installer   — the only DDL. Tables + schema version + migrations.
 *   Repository  — the only SQL. FW_Membership_Plans / _Memberships.
 *   Service     — the only business rules and the only thing that fires events.
 *   Admin/REST  — presentation and transport, over the same service.
 */
class FW_Extension_Membership extends FW_Extension {

	/**
	 * @internal
	 */
	public function _init() {
		$dir = dirname( __FILE__ ) . '/includes/';

		require_once $dir . 'class-fw-membership-installer.php';
		require_once $dir . 'class-fw-membership-plans.php';
		require_once $dir . 'class-fw-membership-memberships.php';
		require_once $dir . 'class-fw-membership-events.php';
		require_once $dir . 'class-fw-membership-service.php';
		require_once $dir . 'class-fw-membership-modules.php';
		// The access engine and every gate that asks it.
		require_once $dir . 'class-fw-membership-rules.php';
		require_once $dir . 'class-fw-membership-access.php';
		require_once $dir . 'class-fw-membership-cache.php';
		require_once $dir . 'class-fw-membership-denied.php';
		require_once $dir . 'class-fw-membership-protect.php';
		require_once $dir . 'class-fw-membership-elements.php';
		require_once $dir . 'class-fw-membership-menus.php';
		require_once $dir . 'class-fw-membership-shortcode.php';
		require_once $dir . 'class-fw-membership-blocks.php';
		// Accounts: settings, sign-up through Forms, email confirmation and approval,
		// login and account pages, member emails, privacy.
		require_once $dir . 'class-fw-membership-settings.php';
		require_once $dir . 'class-fw-membership-emails.php';
		require_once $dir . 'class-fw-membership-verification.php';
		require_once $dir . 'class-fw-membership-accounts.php';
		require_once $dir . 'class-fw-membership-privacy.php';
		require_once $dir . 'class-fw-membership-profile.php';
		require_once $dir . 'class-fw-membership-rest.php';
		require_once $dir . 'ai-abilities.php'; // AI Assistant abilities (registered only while that extension is active)
		require_once $dir . 'forms/class-fw-membership-form-fields.php';

		// One autoloaded get_option() when current, so activation and plugin-update
		// upgrades heal themselves with no activation hook to miss.
		FW_Membership_Installer::maybe_install();

		FW_Membership_Service::init();
		FW_Membership_Access::init();
		FW_Membership_Cache::init();
		FW_Membership_Denied::init();
		FW_Membership_Protect::init();
		FW_Membership_Elements::init();
		FW_Membership_Menus::init();
		FW_Membership_Shortcode::init();
		FW_Membership_Blocks::init();
		FW_Membership_Emails::init();
		FW_Membership_Verification::init();
		FW_Membership_Accounts::init();
		FW_Membership_Privacy::init();
		FW_Membership_Form_Fields::init();
		FW_Membership_REST::init();
		FW_Membership_Modules::load();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once $dir . 'class-fw-membership-cli.php';
			WP_CLI::add_command( 'membership', 'FW_Membership_CLI' );
		}

		// A workspace: a role can open to Membership instead of the WordPress dashboard (core 3.0.55+).
		add_action( 'fw_workspaces_register', function () {
			fw_register_workspace( 'membership', array(
				'label'       => __( 'Membership', 'fw' ),
				'icon'        => 'dashicons-awards',
				'page'        => 'fw-membership',
				'capability'  => FW_Membership_Service::capability(),
				'description' => __( 'Members, plans and access. For staff who look after a members’ site.', 'fw' ),
				// The one-click set-up (Admin Menu → Roles): members (Subscribers) are kept out of wp-admin.
				'members'     => array( 'subscriber' ),
				'setup'       => __( 'Run a members’ site', 'fw' ),
			) );
		} );
		// Members kept out of wp-admin land on their account page.
		add_filter( 'fw_workspaces_member_home', function ( $url ) {
			$account = FW_Membership_Settings::account_url();
			return 0 === strpos( $account, admin_url() ) ? $url : $account;
		} );

		if ( is_admin() ) {
			require_once $dir . 'admin/class-fw-membership-app.php';
			require_once $dir . 'admin/class-fw-membership-admin-page.php';
			require_once $dir . 'admin/class-fw-membership-dashboard.php';
			require_once $dir . 'admin/class-fw-membership-admin-plans.php';
			require_once $dir . 'admin/class-fw-membership-admin-members.php';
			require_once $dir . 'admin/class-fw-membership-admin-rules.php';
			require_once $dir . 'admin/class-fw-membership-metabox.php';
			require_once $dir . 'admin/class-fw-membership-admin-settings.php';
			require_once $dir . 'admin/class-fw-membership-health.php';

			new FW_Membership_Admin_Page( $this );
			FW_Membership_Health::init();
			FW_Membership_Admin_Rules::init();
			FW_Membership_Metabox::init();
			FW_Membership_Export::init();
		}
	}
}
