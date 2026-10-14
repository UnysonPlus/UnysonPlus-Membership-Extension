<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Protected downloads. Files live in uploads/unysonplus/membership-files-<random>/ (the
 * folder name is random per site, denied to the web by .htaccess and web.config, and
 * every file is stored under a random name), so there is no URL to guess or share. They
 * are served only through ?fw_mbr_download=<id>, which checks who may have it, the
 * monthly limit, and logs the download.
 *
 *   who      { plans: int[], any_member: bool, logged_in: bool } (a plan counts for the
 *            plans it includes; managers always may)
 *   limit    downloads per person per month (0 = no limit)
 *
 * Tables fw_mbr_files and fw_mbr_downloads (module_tables()).
 */
class FW_Membership_Files {

	const VERSION = '1.0.0';
	const DIR_OPT = 'fw_ext_membership_files_dir';

	public static function init() {
		FW_Membership_Installer::module_tables( 'files', self::VERSION, array( __CLASS__, 'schema' ), array( 'files', 'downloads' ) );
		add_action( 'init', array( __CLASS__, '_serve_early' ), 99 );
	}

	/** @internal */
	public static function schema( $collate ) {
		$f = FW_Membership_Installer::table( 'files' );
		$d = FW_Membership_Installer::table( 'downloads' );
		return array(
			"CREATE TABLE {$f} (
	id bigint(20) unsigned NOT NULL auto_increment,
	title varchar(190) NOT NULL default '',
	stored varchar(190) NOT NULL default '',
	original varchar(190) NOT NULL default '',
	mime varchar(100) NOT NULL default '',
	size bigint(20) unsigned NOT NULL default 0,
	who longtext,
	monthly_limit int(11) unsigned NOT NULL default 0,
	downloads bigint(20) unsigned NOT NULL default 0,
	status varchar(20) NOT NULL default 'active',
	created_at datetime default NULL,
	PRIMARY KEY  (id),
	KEY status (status)
) {$collate};",
			"CREATE TABLE {$d} (
	id bigint(20) unsigned NOT NULL auto_increment,
	file_id bigint(20) unsigned NOT NULL default 0,
	user_id bigint(20) unsigned NOT NULL default 0,
	ip_hash varchar(64) NOT NULL default '',
	created_at datetime default NULL,
	PRIMARY KEY  (id),
	KEY file_time (file_id,created_at),
	KEY user_time (user_id,file_id,created_at)
) {$collate};",
		);
	}

	/** @return string The protected folder (made and locked on first use). */
	public static function dir() {
		$name = (string) get_option( self::DIR_OPT, '' );
		if ( '' === $name ) {
			$name = 'membership-files-' . strtolower( wp_generate_password( 16, false, false ) );
			update_option( self::DIR_OPT, $name, false );
		}
		$path = function_exists( 'fw_upw_uploads_dir' ) ? fw_upw_uploads_dir( $name )['path'] : wp_normalize_path( wp_upload_dir()['basedir'] . '/unysonplus/' . $name );
		if ( ! is_dir( $path ) ) {
			wp_mkdir_p( $path );
		}
		if ( ! file_exists( $path . '/.htaccess' ) ) {
			file_put_contents( $path . '/.htaccess', "# Unyson+ Membership: served only through an access check.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $path . '/web.config', '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $path . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $path;
	}

	/* ---------------------------------------------------------------------- *
	 * Repository
	 * ---------------------------------------------------------------------- */

	public static function get( $id ) {
		global $wpdb;
		$t   = FW_Membership_Installer::table( 'files' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::shape( $row ) : null;
	}

	/** @return array[] */
	public static function all( $status = 'active' ) {
		global $wpdb;
		$t = FW_Membership_Installer::table( 'files' );
		$rows = '' === $status
			? $wpdb->get_results( "SELECT * FROM {$t} ORDER BY title ASC", ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = %s ORDER BY title ASC", $status ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	/**
	 * Store an uploaded or local file.
	 *
	 * @param string $source   A path to copy (or an uploaded tmp file to move).
	 * @param string $original The original file name.
	 * @param array  $data     title, who, monthly_limit
	 * @param bool   $uploaded $source is a PHP upload (moved, not copied).
	 *
	 * @return int|WP_Error
	 */
	public static function add( $source, $original, array $data, $uploaded = false ) {
		global $wpdb;
		$original = sanitize_file_name( (string) $original );
		$check    = wp_check_filetype_and_ext( $source, $original );
		if ( ! $check['ext'] || ! $check['type'] ) {
			return new WP_Error( 'fw_mbr_file_type', __( 'That type of file is not allowed on this site.', 'fw' ) );
		}
		$stored = strtolower( wp_generate_password( 24, false, false ) ) . '.' . $check['ext'];
		$dest   = self::dir() . '/' . $stored;
		$ok     = $uploaded ? @move_uploaded_file( $source, $dest ) : @copy( $source, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $ok ) {
			return new WP_Error( 'fw_mbr_file_store', __( 'The file could not be stored. Check that the uploads folder is writable.', 'fw' ) );
		}
		$wpdb->insert( FW_Membership_Installer::table( 'files' ), array(
			'title'         => sanitize_text_field( (string) ( $data['title'] ?? '' ) ) ?: pathinfo( $original, PATHINFO_FILENAME ),
			'stored'        => $stored,
			'original'      => $original,
			'mime'          => $check['type'],
			'size'          => (int) filesize( $dest ),
			'who'           => wp_json_encode( self::clean_who( (array) ( $data['who'] ?? array() ) ) ),
			'monthly_limit' => max( 0, (int) ( $data['monthly_limit'] ?? 0 ) ),
			'status'        => 'active',
			'created_at'    => current_time( 'mysql' ),
		) );
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$row = array();
		if ( isset( $data['title'] ) ) {
			$row['title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( isset( $data['who'] ) ) {
			$row['who'] = wp_json_encode( self::clean_who( (array) $data['who'] ) );
		}
		if ( isset( $data['monthly_limit'] ) ) {
			$row['monthly_limit'] = max( 0, (int) $data['monthly_limit'] );
		}
		return $row ? false !== $wpdb->update( FW_Membership_Installer::table( 'files' ), $row, array( 'id' => (int) $id ) ) : true;
	}

	public static function delete( $id ) {
		global $wpdb;
		$f = self::get( $id );
		if ( ! $f ) {
			return;
		}
		$path = self::dir() . '/' . $f['stored'];
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
		$wpdb->delete( FW_Membership_Installer::table( 'files' ), array( 'id' => (int) $id ) );
		$wpdb->delete( FW_Membership_Installer::table( 'downloads' ), array( 'file_id' => (int) $id ) );
	}

	private static function clean_who( array $w ) {
		return array(
			'plans'      => array_values( array_filter( array_map( 'intval', (array) ( $w['plans'] ?? array() ) ) ) ),
			'any_member' => ! empty( $w['any_member'] ),
			'logged_in'  => ! empty( $w['logged_in'] ),
		);
	}

	private static function shape( array $row ) {
		foreach ( array( 'id', 'size', 'monthly_limit', 'downloads' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$row['who'] = self::clean_who( (array) json_decode( (string) $row['who'], true ) );
		return $row;
	}

	/* ---------------------------------------------------------------------- *
	 * Access
	 * ---------------------------------------------------------------------- */

	/**
	 * May this person download the file (not counting the monthly limit)?
	 *
	 * @param array $f
	 * @param int   $user_id
	 *
	 * @return bool
	 */
	public static function can( array $f, $user_id ) {
		$user_id = (int) $user_id;
		if ( 'active' !== $f['status'] ) {
			return false;
		}
		if ( $user_id && user_can( $user_id, FW_Membership_Service::capability() ) ) {
			return true;
		}
		$w = $f['who'];
		if ( ! $user_id ) {
			return false;
		}
		if ( $w['logged_in'] ) {
			return true;
		}
		$plans = FW_Membership_Service::user_plans( $user_id );
		if ( $w['any_member'] && $plans ) {
			return true;
		}
		if ( $w['plans'] && array_intersect( $w['plans'], $plans ) ) {
			return true;
		}
		return ! $w['plans'] && ! $w['any_member'] && $plans; // nothing chosen = any member
	}

	/**
	 * Downloads this month.
	 *
	 * @return int
	 */
	public static function used( $file_id, $user_id ) {
		global $wpdb;
		$t = FW_Membership_Installer::table( 'downloads' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE file_id = %d AND user_id = %d AND created_at >= %s", (int) $file_id, (int) $user_id, gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DateTime.CurrentTimeTimestamp
	}

	/**
	 * Why a person may not download it now, or null.
	 *
	 * @return WP_Error|null
	 */
	public static function refuse( array $f, $user_id ) {
		if ( ! self::can( $f, $user_id ) ) {
			return new WP_Error( 'fw_mbr_file_denied', $user_id ? __( 'This download is part of a membership plan you do not hold.', 'fw' ) : __( 'Log in to download this file.', 'fw' ), array( 'status' => $user_id ? 403 : 401 ) );
		}
		if ( $f['monthly_limit'] && ! user_can( (int) $user_id, FW_Membership_Service::capability() ) && self::used( $f['id'], $user_id ) >= $f['monthly_limit'] ) {
			return new WP_Error( 'fw_mbr_file_limit', sprintf( _n( 'You can download this %d time a month, and have done so already. It opens again next month.', 'You can download this %d times a month, and have done so already. It opens again next month.', $f['monthly_limit'], 'fw' ), $f['monthly_limit'] ), array( 'status' => 429 ) );
		}
		return null;
	}

	/** @return string The download address for a file. */
	public static function url( $id ) {
		return add_query_arg( 'fw_mbr_download', (int) $id, home_url( '/' ) );
	}

	/** @internal Serve as soon as WordPress has the user (before the theme). */
	public static function _serve_early() {
		if ( isset( $_GET['fw_mbr_download'] ) && ! is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::_serve();
		}
	}

	/** @internal */
	public static function _serve() {
		if ( ! isset( $_GET['fw_mbr_download'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$f   = self::get( (int) $_GET['fw_mbr_download'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$uid = get_current_user_id();
		if ( ! $f ) {
			wp_die( esc_html__( 'This file is not available.', 'fw' ), '', array( 'response' => 404 ) );
		}
		$no = self::refuse( $f, $uid );
		if ( $no ) {
			if ( ! $uid ) {
				wp_safe_redirect( FW_Membership_Settings::login_url( self::url( $f['id'] ) ) );
				exit;
			}
			wp_die( esc_html( $no->get_error_message() ), '', array( 'response' => (int) $no->get_error_data()['status'], 'back_link' => true ) );
		}
		$path = self::dir() . '/' . $f['stored'];
		if ( ! is_file( $path ) ) {
			wp_die( esc_html__( 'This file is missing on the server.', 'fw' ), '', array( 'response' => 410 ) );
		}
		self::log( $f['id'], $uid );
		self::send( $path, $f );
	}

	/** Record a download. */
	public static function log( $file_id, $user_id ) {
		global $wpdb;
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$wpdb->insert( FW_Membership_Installer::table( 'downloads' ), array(
			'file_id'    => (int) $file_id,
			'user_id'    => (int) $user_id,
			'ip_hash'    => '' !== $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) : '',
			'created_at' => current_time( 'mysql' ),
		) );
		$t = FW_Membership_Installer::table( 'files' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET downloads = downloads + 1 WHERE id = %d", (int) $file_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		/** Fires after a protected file is served. */
		do_action( 'unysonplus_membership_file_downloaded', (int) $file_id, (int) $user_id );
	}

	private static function send( $path, array $f ) {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . ( '' !== $f['mime'] ? $f['mime'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $f['original'] ) . '"; filename*=UTF-8\'\'' . rawurlencode( $f['original'] ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		/** Let the web server send the file (X-Sendfile / X-Accel-Redirect): return true after setting the header. */
		if ( ! apply_filters( 'unysonplus_membership_file_send', false, $path, $f ) ) {
			$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			while ( $fh && ! feof( $fh ) ) {
				echo fread( $fh, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions
				flush();
			}
			if ( $fh ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		exit;
	}

	/** @return array[] The latest downloads (for the log). */
	public static function recent( $limit = 50, $file_id = 0 ) {
		global $wpdb;
		$t = FW_Membership_Installer::table( 'downloads' );
		$w = $file_id ? $wpdb->prepare( 'WHERE file_id = %d', (int) $file_id ) : '';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} {$w} ORDER BY id DESC LIMIT %d", (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
