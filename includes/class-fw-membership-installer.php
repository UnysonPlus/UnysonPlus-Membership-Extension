<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Schema owner. The ONLY class in the extension that emits DDL.
 *
 * Same discipline as the CRM's installer (see AGENTS.md):
 *  - dbDelta() formatting: two spaces after PRIMARY KEY, one field per line,
 *    lowercase types, KEY (never INDEX), the collation from get_charset_collate().
 *  - No ENUM columns (statuses are varchar, whitelisted in PHP), no zero-date
 *    defaults (NULL means "not set"), no foreign keys to wp_users. Each of those
 *    breaks on some host, on strict SQL mode or on multisite.
 *  - Guarded by DB_VERSION_OPTION, so it costs one autoloaded get_option() when current.
 *  - dbDelta() only adds; anything destructive is a numbered block in migrate().
 *  - $wpdb->prefix (per site), and deactivating never drops a table.
 */
class FW_Membership_Installer {

	/** Bump when the schema below changes. */
	const DB_VERSION = '1.0.1';

	const DB_VERSION_OPTION = 'fw_ext_membership_db_version';

	/**
	 * @param string $table plans|prices|memberships|membership_events|rules|rule_targets|payments
	 *
	 * @return string
	 */
	public static function table( $table ) {
		global $wpdb;

		return $wpdb->prefix . 'fw_mbr_' . $table;
	}

	/** Install or upgrade if the stored version differs. Cheap no-op otherwise. */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$from = (string) get_option( self::DB_VERSION_OPTION, '' );

		foreach ( self::schema( $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}

		self::migrate( $from );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * The schema.
	 *
	 *  - A PLAN is what someone gets; a PRICE is one way to pay for it (a plan can
	 *    have a monthly and a yearly price, or none: free). Payments arrive in phase 3,
	 *    but prices are their own rows from the start so no migration splits them later.
	 *  - A MEMBERSHIP is one person (a WordPress user) holding one plan, with a status
	 *    and the source that granted it. Several per person are allowed.
	 *  - MEMBERSHIP_EVENTS is append-only: the history of every change, never updated.
	 *  - RULES and RULE_TARGETS hold access rules. A target is an indexed row
	 *    (type + sub type + object id), so the access engine looks rules up from the
	 *    request's context in one query instead of testing every rule.
	 *
	 * @param string $collate
	 *
	 * @return string[]
	 */
	private static function schema( $collate ) {
		$plans       = self::table( 'plans' );
		$prices      = self::table( 'prices' );
		$memberships = self::table( 'memberships' );
		$events      = self::table( 'membership_events' );
		$rules       = self::table( 'rules' );
		$targets     = self::table( 'rule_targets' );
		$payments    = self::table( 'payments' );

		$sql = array();

		$sql[] = "CREATE TABLE {$plans} (
	id bigint(20) unsigned NOT NULL auto_increment,
	slug varchar(100) NOT NULL default '',
	name varchar(190) NOT NULL default '',
	description text,
	status varchar(20) NOT NULL default 'active',
	priority int(11) NOT NULL default 0,
	parent_id bigint(20) unsigned NOT NULL default 0,
	duration_value int(11) unsigned NOT NULL default 0,
	duration_unit varchar(10) NOT NULL default '',
	fixed_end_date datetime default NULL,
	signup_limit int(11) unsigned NOT NULL default 0,
	settings longtext,
	created_at datetime default NULL,
	updated_at datetime default NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY slug (slug),
	KEY status_priority (status,priority)
) {$collate};";

		$sql[] = "CREATE TABLE {$prices} (
	id bigint(20) unsigned NOT NULL auto_increment,
	plan_id bigint(20) unsigned NOT NULL default 0,
	label varchar(190) NOT NULL default '',
	amount decimal(13,2) NOT NULL default 0.00,
	currency varchar(3) NOT NULL default '',
	billing varchar(20) NOT NULL default 'once',
	interval_count int(11) unsigned NOT NULL default 1,
	trial_days int(11) unsigned NOT NULL default 0,
	setup_fee decimal(13,2) NOT NULL default 0.00,
	status varchar(20) NOT NULL default 'active',
	sort_order int(11) NOT NULL default 0,
	gateway_refs longtext,
	created_at datetime default NULL,
	PRIMARY KEY  (id),
	KEY plan_id (plan_id,status,sort_order)
) {$collate};";

		$sql[] = "CREATE TABLE {$memberships} (
	id bigint(20) unsigned NOT NULL auto_increment,
	user_id bigint(20) unsigned NOT NULL default 0,
	plan_id bigint(20) unsigned NOT NULL default 0,
	price_id bigint(20) unsigned NOT NULL default 0,
	status varchar(20) NOT NULL default 'pending',
	source varchar(30) NOT NULL default 'manual',
	source_ref varchar(190) NOT NULL default '',
	gateway varchar(30) NOT NULL default '',
	gateway_subscription_id varchar(190) NOT NULL default '',
	started_at datetime default NULL,
	trial_ends_at datetime default NULL,
	renews_at datetime default NULL,
	ends_at datetime default NULL,
	cancelled_at datetime default NULL,
	meta longtext,
	created_at datetime default NULL,
	updated_at datetime default NULL,
	PRIMARY KEY  (id),
	KEY user_status (user_id,status),
	KEY plan_status (plan_id,status),
	KEY status_ends (status,ends_at),
	KEY gateway_sub (gateway_subscription_id)
) {$collate};";

		$sql[] = "CREATE TABLE {$events} (
	id bigint(20) unsigned NOT NULL auto_increment,
	membership_id bigint(20) unsigned NOT NULL default 0,
	user_id bigint(20) unsigned NOT NULL default 0,
	plan_id bigint(20) unsigned NOT NULL default 0,
	type varchar(40) NOT NULL default '',
	status_from varchar(20) NOT NULL default '',
	status_to varchar(20) NOT NULL default '',
	note text,
	meta longtext,
	actor_id bigint(20) unsigned NOT NULL default 0,
	created_at datetime default NULL,
	PRIMARY KEY  (id),
	KEY membership_time (membership_id,created_at),
	KEY user_time (user_id,created_at),
	KEY type_time (type,created_at)
) {$collate};";

		$sql[] = "CREATE TABLE {$rules} (
	id bigint(20) unsigned NOT NULL auto_increment,
	name varchar(190) NOT NULL default '',
	status varchar(20) NOT NULL default 'active',
	who longtext,
	denied longtext,
	drip_days int(11) unsigned NOT NULL default 0,
	drip_date datetime default NULL,
	priority int(11) NOT NULL default 0,
	created_at datetime default NULL,
	updated_at datetime default NULL,
	PRIMARY KEY  (id),
	KEY status (status)
) {$collate};";

		$sql[] = "CREATE TABLE {$targets} (
	id bigint(20) unsigned NOT NULL auto_increment,
	rule_id bigint(20) unsigned NOT NULL default 0,
	mode varchar(10) NOT NULL default 'include',
	type varchar(20) NOT NULL default '',
	sub_type varchar(100) NOT NULL default '',
	object_id bigint(20) unsigned NOT NULL default 0,
	pattern varchar(255) NOT NULL default '',
	PRIMARY KEY  (id),
	KEY lookup (type,sub_type,object_id),
	KEY rule_id (rule_id)
) {$collate};";

		$sql[] = "CREATE TABLE {$payments} (
	id bigint(20) unsigned NOT NULL auto_increment,
	membership_id bigint(20) unsigned NOT NULL default 0,
	user_id bigint(20) unsigned NOT NULL default 0,
	plan_id bigint(20) unsigned NOT NULL default 0,
	price_id bigint(20) unsigned NOT NULL default 0,
	type varchar(20) NOT NULL default 'payment',
	status varchar(20) NOT NULL default 'pending',
	amount decimal(13,2) NOT NULL default 0.00,
	tax decimal(13,2) NOT NULL default 0.00,
	currency varchar(3) NOT NULL default '',
	gateway varchar(30) NOT NULL default '',
	gateway_ref varchar(190) NOT NULL default '',
	coupon varchar(100) NOT NULL default '',
	invoice_number varchar(50) NOT NULL default '',
	meta longtext,
	created_at datetime default NULL,
	PRIMARY KEY  (id),
	KEY membership_id (membership_id),
	KEY user_time (user_id,created_at),
	KEY status_time (status,created_at),
	KEY gateway_ref (gateway_ref)
) {$collate};";

		return $sql;
	}

	/**
	 * Numbered, version-guarded data moves. Empty for 1.0.0 by definition.
	 *
	 * @param string $from Previously installed version ('' on a fresh install).
	 */
	private static function migrate( $from ) {
		if ( '' === $from ) {
			return;
		}
	}

	/**
	 * A module's own tables: created or upgraded with dbDelta the first time the module
	 * loads at a new version, then a cheap no-op. Tables are named fw_mbr_<name>.
	 *
	 * @param string   $module  Module slug.
	 * @param string   $version Bump to apply a schema change.
	 * @param callable $schema  function( $collate ) => string[] CREATE TABLE statements.
	 * @param string[] $tables  Table names (without prefix), dropped by "Remove all data".
	 */
	public static function module_tables( $module, $version, $schema, array $tables ) {
		$known = (array) get_option( 'fw_ext_membership_module_tables', array() );
		if ( ( $known[ $module ]['v'] ?? '' ) === $version ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( (array) call_user_func( $schema, $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
		$known[ $module ] = array( 'v' => $version, 'tables' => array_values( $tables ) );
		update_option( 'fw_ext_membership_module_tables', $known, true );
	}

	/**
	 * Drop everything. Only ever called from the explicit, opt-in "Remove all data"
	 * action — never on deactivation.
	 */
	public static function uninstall() {
		global $wpdb;

		$module_tables = array();
		foreach ( (array) get_option( 'fw_ext_membership_module_tables', array() ) as $m ) {
			$module_tables = array_merge( $module_tables, (array) ( $m['tables'] ?? array() ) );
		}
		foreach ( array_merge( $module_tables, array( 'payments', 'rule_targets', 'rules', 'membership_events', 'memberships', 'prices', 'plans' ) ) as $table ) {
			$name = self::table( sanitize_key( $table ) );
			$wpdb->query( "DROP TABLE IF EXISTS {$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		delete_option( 'fw_ext_membership_module_tables' );

		delete_option( self::DB_VERSION_OPTION );
		delete_option( FW_Membership_Modules::OPTION );
		wp_clear_scheduled_hook( FW_Membership_Service::CRON_HOOK );
		/** The modules' scheduled jobs (they schedule themselves again only while switched on). */
		foreach ( (array) apply_filters( 'unysonplus_membership_cron_hooks', array( 'fw_mbr_drip_daily', 'fw_mbr_retention_daily' ) ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
