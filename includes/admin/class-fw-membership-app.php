<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Membership app shell: the frame every Membership screen renders inside.
 *
 * Built to work exactly like the CRM app (crm/includes/admin/class-fw-crm-app.php),
 * so the two read as one family: one admin page (`fw-membership`) whose `tab` picks
 * the view, a left sidebar with every area, and a top bar (title, member search and
 * a "New" menu). On Membership screens only:
 *  - the WordPress admin menu folds to icons (the core `folded` body class);
 *  - focus mode (per user, toggled from the sidebar) hides the WordPress menu
 *    altogether, with "Back to WordPress" as the way out.
 */
class FW_Membership_App {

	/** @var FW_Admin_App|null */
	protected static $app = null;

	/**
	 * The shell, built once.
	 *
	 * The frame — the folded WordPress menu, the sidebar, the top bar, focus
	 * mode — is the framework's `FW_Admin_App`. What stays here is the part that
	 * is about Membership: its menu, its top-bar search and New menu, and the
	 * small shared pieces its screens draw with.
	 *
	 * @return FW_Admin_App
	 */
	public static function app() {
		if ( self::$app ) {
			return self::$app;
		}

		if ( ! class_exists( 'FW_Admin_App' ) ) {
			require_once fw_get_framework_directory( '/includes/admin-app/class-fw-admin-app.php' );
		}

		self::$app = new FW_Admin_App( 'membership', array(
			'brand'      => array(
				'label' => __( 'Membership', 'fw' ),
				'icon'  => 'dashicons-awards',
				'url'   => FW_Membership_Admin_Page::get_page_url( 'home' ),
			),
			'page'       => FW_Membership_Admin_Page::PAGE_SLUG,
			'arg'        => 'tab',
			'default'    => 'home',
			'capability' => FW_Membership_Service::capability(),
			'nav'        => array( __CLASS__, 'nav' ),
		) );

		return self::$app;
	}

	/**
	 * Claim the Membership screen. Called once from the admin page, on `admin_menu`.
	 *
	 * @param string $hook_suffix The Membership page's hook suffix.
	 */
	public static function init( $hook_suffix ) {
		self::app()->attach( $hook_suffix );
	}

	/** @return bool Whether this person is hiding the WordPress menu on app screens. */
	public static function is_focus() {
		return FW_Admin_App::is_focus();
	}

	/* ---------------------------------------------------------------------- *
	 * Navigation
	 * ---------------------------------------------------------------------- */

	/**
	 * The sidebar, in groups. `soon` marks an area that is planned but not built yet:
	 * it stays in the menu so the shape of the extension is visible, and its screen
	 * says plainly what it will do.
	 *
	 * @return array group => { label, items: view => { label, icon, soon?, url? } }
	 */
	public static function nav() {
		$nav = array(
			'main'    => array(
				'label' => '',
				'items' => array(
					'home'    => array( 'label' => __( 'Home', 'fw' ), 'icon' => 'dashicons-admin-home' ),
					'members' => array( 'label' => __( 'Members', 'fw' ), 'icon' => 'dashicons-groups' ),
					'plans'   => array( 'label' => __( 'Plans', 'fw' ), 'icon' => 'dashicons-awards' ),
					'rules'   => array( 'label' => __( 'Access rules', 'fw' ), 'icon' => 'dashicons-lock' ),
				),
			),
			'selling' => array(
				'label' => __( 'Selling', 'fw' ),
				'items' => array(
					'payments' => array( 'label' => __( 'Payments', 'fw' ), 'icon' => 'dashicons-money-alt', 'soon' => true ),
					'coupons'  => array( 'label' => __( 'Coupons', 'fw' ), 'icon' => 'dashicons-tickets-alt', 'soon' => true ),
				),
			),
			'insights' => array(
				'label' => __( 'Insights', 'fw' ),
				'items' => array(
					'reports' => array( 'label' => __( 'Reports', 'fw' ), 'icon' => 'dashicons-chart-bar', 'soon' => true ),
				),
			),
			'setup'   => array(
				'label' => __( 'Setup', 'fw' ),
				'items' => array(
					'fields'   => array( 'label' => __( 'Profile fields', 'fw' ), 'icon' => 'dashicons-id' ),
					'emails'   => array( 'label' => __( 'Emails', 'fw' ), 'icon' => 'dashicons-email-alt' ),
					'modules'  => array( 'label' => __( 'Modules', 'fw' ), 'icon' => 'dashicons-screenoptions' ),
					'settings' => array( 'label' => __( 'Settings', 'fw' ), 'icon' => 'dashicons-admin-generic' ),
				),
			),
		);

		/**
		 * Filters the Membership sidebar. Modules add their areas here:
		 * group => { label, items: view => { label, icon, soon?, url? } }.
		 * A view added here renders on the `unysonplus_membership_render_view` action.
		 */
		return (array) apply_filters( 'unysonplus_membership_nav', $nav );
	}

	/**
	 * Whether the current person may open a view. A nav item may carry a `cap`; without
	 * it the item is hidden from the sidebar and its screen shows a locked state (the
	 * same convention as the CRM app).
	 *
	 * @param string $view
	 *
	 * @return bool
	 */
	public static function can_view( $view ) {
		$item = self::item( $view );

		return ! $item || empty( $item['cap'] ) || current_user_can( $item['cap'] );
	}

	/**
	 * @return string[] Every view slug the sidebar knows.
	 */
	public static function views() {
		$views = array( 'search' );
		foreach ( self::nav() as $group ) {
			$views = array_merge( $views, array_keys( (array) $group['items'] ) );
		}
		return $views;
	}

	/**
	 * @param string $view
	 *
	 * @return array|null
	 */
	public static function item( $view ) {
		foreach ( self::nav() as $group ) {
			if ( isset( $group['items'][ $view ] ) ) {
				return $group['items'][ $view ];
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------------- *
	 * Frame
	 * ---------------------------------------------------------------------- */

	/**
	 * Open the frame: sidebar, top bar, then the content column (left open).
	 *
	 * @param string $view    The active view (sidebar highlight).
	 * @param array  $crumbs  [ [ label, url? ], … ] — the last one is the page title.
	 * @param string $actions Extra top-bar HTML (already escaped), right of search.
	 */
	public static function open( $view, array $crumbs, $actions = '' ) {
		$last  = count( $crumbs ) - 1;
		$title = $last >= 0 ? (string) $crumbs[ $last ][0] : '';

		self::app()->open( $view, array(
			'title'   => $title,
			'crumbs'  => $last > 0 ? array_slice( $crumbs, 0, $last ) : array(),
			'search'  => self::search_form(),
			'actions' => $actions . self::new_menu(),
			// The content column keeps this extension's own classes: its screens
			// style themselves against `.fw-mbr-body`.
			'class'   => 'fw-ext-membership fw-mbr-body',
		) );
	}

	/**
	 * The top bar's member search.
	 *
	 * @return string
	 */
	protected static function search_form() {
		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		ob_start();
		?>
		<form class="fw-mbr-search" role="search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( FW_Membership_Admin_Page::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="members" />
			<label class="screen-reader-text" for="fw-mbr-q"><?php esc_html_e( 'Search members', 'fw' ); ?></label>
			<span class="dashicons dashicons-search" aria-hidden="true"></span>
			<input type="search" id="fw-mbr-q" name="q" value="<?php echo esc_attr( $query ); ?>"
			       placeholder="<?php esc_attr_e( 'Search members by name or email', 'fw' ); ?>" autocomplete="off" />
			<kbd aria-hidden="true">/</kbd>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * The top bar's "New" menu.
	 *
	 * @return string
	 */
	protected static function new_menu() {
		$base = FW_Membership_Admin_Page::get_page_url();

		ob_start();
		?>
		<details class="fw-mbr-new">
			<summary class="button button-primary">
				<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'New', 'fw' ); ?>
			</summary>
			<ul class="fw-mbr-new__menu">
				<li><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'members', 'new' => 1 ), $base ) ); ?>"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><?php esc_html_e( 'Add a member', 'fw' ); ?></a></li>
				<li><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'plans', 'new' => 1 ), $base ) ); ?>"><span class="dashicons dashicons-awards" aria-hidden="true"></span><?php esc_html_e( 'Plan', 'fw' ); ?></a></li>
				<li><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'rules', 'new' => 1 ), $base ) ); ?>"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Access rule', 'fw' ); ?></a></li>
			</ul>
		</details>
		<?php

		return (string) ob_get_clean();
	}

	/** Close the frame opened by open(). */
	public static function close() {
		self::app()->close();
	}

	/**
	 * Is the CRM active, and may the current person open it? Since CRM 1.0.46 the CRM
	 * has its own capability (fw_crm_access), separate from Membership's.
	 *
	 * @param int $contact_id 0 for the CRM itself, or a contact the link opens: a
	 *                        sales rep may only open contacts they own.
	 *
	 * @return bool
	 */
	public static function can_open_crm( $contact_id = 0 ) {
		if ( ! function_exists( 'fw_ext' ) || ! fw_ext( 'crm' ) || ! class_exists( 'FW_CRM_Admin_Page' ) || ! class_exists( 'FW_CRM_Service' ) ) {
			return false;
		}
		if ( ! current_user_can( FW_CRM_Service::capability() ) ) {
			return false;
		}
		if ( $contact_id && class_exists( 'FW_CRM_Capabilities' ) && method_exists( 'FW_CRM_Capabilities', 'can_edit_contact' ) ) {
			return (bool) FW_CRM_Capabilities::can_edit_contact( (int) $contact_id );
		}
		return true;
	}

	/* ---------------------------------------------------------------------- *
	 * Small shared pieces (same look as the CRM's)
	 * ---------------------------------------------------------------------- */

	/**
	 * Initials in a tinted circle; the hue is derived from the name so a person keeps
	 * their colour everywhere (the same formula as the CRM, so a member looks the same
	 * in both apps).
	 *
	 * @param string $name
	 * @param int    $size px
	 *
	 * @return string
	 */
	public static function avatar( $name, $size = 32 ) {
		$name  = trim( (string) $name );
		$parts = preg_split( '/[\s@._-]+/', $name, -1, PREG_SPLIT_NO_EMPTY );
		$ini   = '';
		foreach ( array_slice( (array) $parts, 0, 2 ) as $p ) {
			$ini .= function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 1 ) : substr( $p, 0, 1 );
		}
		$ini = '' !== $ini ? strtoupper( $ini ) : '?';
		$hue = hexdec( substr( md5( strtolower( $name ) ), 0, 4 ) ) % 360;

		return sprintf(
			'<span class="fw-mbr-avatar fw-mbr-avatar--round" style="--s:%dpx;--h:%d" aria-hidden="true">%s</span>',
			(int) $size,
			(int) $hue,
			esc_html( $ini )
		);
	}

	/**
	 * @param string $status A membership status.
	 *
	 * @return string
	 */
	public static function status_pill( $status ) {
		$labels = self::status_labels();

		return sprintf( '<span class="fw-mbr-mstatus fw-mbr-mstatus--%1$s">%2$s</span>', esc_attr( $status ), esc_html( $labels[ $status ] ?? $status ) );
	}

	/**
	 * @return array status => label
	 */
	public static function status_labels() {
		return array(
			'pending'   => __( 'Pending', 'fw' ),
			'trialing'  => __( 'Trial', 'fw' ),
			'active'    => __( 'Active', 'fw' ),
			'past_due'  => __( 'Past due', 'fw' ),
			'paused'    => __( 'Paused', 'fw' ),
			'cancelled' => __( 'Cancelled', 'fw' ),
			'expired'   => __( 'Expired', 'fw' ),
			'refunded'  => __( 'Refunded', 'fw' ),
		);
	}

	/**
	 * A date, or a dash.
	 *
	 * @param string|null $mysql
	 * @param string      $empty Text when there is no date.
	 *
	 * @return string
	 */
	public static function date( $mysql, $empty = '' ) {
		if ( ! $mysql ) {
			return '' !== $empty ? esc_html( $empty ) : '<span aria-hidden="true">—</span>';
		}
		$t = strtotime( (string) $mysql );

		return sprintf( '<time datetime="%s">%s</time>', esc_attr( date( 'c', $t ) ), esc_html( date_i18n( get_option( 'date_format' ), $t ) ) );
	}

	/**
	 * "3 hours ago", with the exact time on hover.
	 *
	 * @param string|null $mysql A local MySQL datetime.
	 *
	 * @return string
	 */
	public static function time_ago( $mysql ) {
		$time = $mysql ? strtotime( (string) $mysql ) : 0;
		if ( ! $time || $time < 86400 ) {
			return '<span aria-hidden="true">—</span>';
		}
		$now  = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp -- compared with a local datetime.
		$text = $now - $time < 45
			? __( 'just now', 'fw' )
			: ( $time > $now ? sprintf( __( 'in %s', 'fw' ), human_time_diff( $now, $time ) ) : sprintf( __( '%s ago', 'fw' ), human_time_diff( $time, $now ) ) );

		return sprintf( '<time datetime="%s" title="%s">%s</time>', esc_attr( date( 'c', $time ) ), esc_attr( date_i18n( 'Y-m-d H:i', $time ) ), esc_html( $text ) );
	}

	/**
	 * A teaching empty state: what this place is for and the first useful step.
	 *
	 * @param string $icon    A dashicons class.
	 * @param string $title
	 * @param string $text
	 * @param array  $actions [ [ label, url, primary? ], … ]
	 */
	public static function empty_state( $icon, $title, $text, array $actions = array() ) {
		?>
		<div class="fw-mbr-empty">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<h2><?php echo esc_html( $title ); ?></h2>
			<p><?php echo esc_html( $text ); ?></p>
			<?php if ( $actions ) : ?>
				<p class="fw-mbr-empty__actions">
					<?php foreach ( $actions as $a ) : ?>
						<a class="button<?php echo ! empty( $a[2] ) ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $a[1] ); ?>"><?php echo esc_html( $a[0] ); ?></a>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The screen for an area that is planned but not built yet.
	 *
	 * @param string $view
	 */
	public static function render_soon( $view ) {
		$about = array(
			'rules'    => __( 'Choose what is protected (the whole site, post types, single pages, categories, archives, URL patterns), who may see it (plans, roles, logged-in visitors) and what everyone else sees instead: a teaser, a login form, the pricing table or a redirect.', 'fw' ),
			'payments' => __( 'Every payment, renewal and refund in one place, taken with your own payment accounts. Plans get a checkout and a pricing table, with no transaction fee from Unyson+.', 'fw' ),
			'coupons'  => __( 'Discount codes for the first payment or for ever, with usage limits, expiry dates and bulk codes.', 'fw' ),
			'reports'  => __( 'Monthly recurring revenue, new and lost members, churn, lifetime value and trial conversion, by plan and period.', 'fw' ),
			'emails'   => __( 'Every email members receive (welcome, receipt, renewal reminder, payment failed, cancelled, expired), editable with placeholders.', 'fw' ),
			'settings' => __( 'Site-wide defaults: what non-members see, login and registration pages, redirects after login, and removing all Membership data.', 'fw' ),
		);
		$item = self::item( $view );

		$module = array( 'payments' => 'payments', 'coupons' => 'coupons', 'reports' => 'analytics' );
		$reg    = FW_Membership_Modules::registry();
		$name   = isset( $module[ $view ], $reg[ $module[ $view ] ] ) ? $reg[ $module[ $view ] ][0] : ( $item ? $item['label'] : '' );
		self::empty_state(
			$item ? $item['icon'] : 'dashicons-admin-plugins',
			sprintf( __( '%s is switched off', 'fw' ), $item ? $item['label'] : '' ),
			( $about[ $view ] ?? '' ) . ' ' . sprintf( __( 'Switch on the %s module to use it.', 'fw' ), $name ),
			array( array( __( 'Open Modules', 'fw' ), FW_Membership_Admin_Page::get_page_url( 'modules' ), true ) )
		);
	}
}
