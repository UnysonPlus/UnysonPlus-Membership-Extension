<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Certificates. A course can issue one when every lesson is complete. The design is a
 * page made in the page builder (course setting _fw_certificate_page) with the
 * placeholders {name} {course} {date} {code} {verify_url}; without one, a built-in
 * design is used. The certificate link (?fw_mbr_cert=<code>) shows that page filled in,
 * with a "Verified" line, and prints cleanly (save as PDF from the print dialog). Anyone
 * with the link can check it is genuine.
 */
class FW_Membership_Certificates {

	/** @var array|null The certificate being shown on this request. */
	private static $current = null;

	public static function init() {
		add_action( 'unysonplus_membership_course_completed', array( __CLASS__, 'issue' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, '_route' ), 1 );
		add_filter( 'the_content', array( __CLASS__, '_fill' ), 99999 );
		add_filter( 'unysonplus_membership_email_catalogue', array( __CLASS__, '_emails' ) );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, '_box' ) );
			add_action( 'save_post', array( __CLASS__, '_save' ), 31, 2 );
		}
	}

	/** @return string The certificate link. */
	public static function url( $code ) {
		return add_query_arg( 'fw_mbr_cert', rawurlencode( $code ), home_url( '/' ) );
	}

	/** @return array|null { code, user_id, course_id, completed_at } */
	public static function find( $code ) {
		global $wpdb;
		$t = FW_Membership_Installer::table( 'course_enrollments' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE certificate = %s AND certificate <> ''", strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $code ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Issue a certificate for a completed course (once).
	 *
	 * @param int $user_id
	 * @param int $course_id
	 *
	 * @return string The code ('' when the course gives none).
	 */
	public static function issue( $user_id, $course_id ) {
		if ( ! get_post_meta( $course_id, '_fw_certificate', true ) ) {
			return '';
		}
		$e = FW_Membership_Progress::enrollment( $user_id, $course_id );
		if ( ! $e || '' !== (string) $e['certificate'] ) {
			return $e ? (string) $e['certificate'] : '';
		}
		$code = 'C' . strtoupper( wp_generate_password( 11, false, false ) );
		global $wpdb;
		$wpdb->update( FW_Membership_Installer::table( 'course_enrollments' ), array( 'certificate' => $code ), array( 'user_id' => (int) $user_id, 'course_id' => (int) $course_id ) );
		FW_Membership_Emails::send( 'certificate', $user_id, array( 'course' => get_the_title( $course_id ), 'certificate_url' => self::url( $code ) ) );
		return $code;
	}

	/** @return array Placeholder values for a certificate. */
	private static function values( array $row ) {
		$u = get_userdata( (int) $row['user_id'] );
		return array(
			'{name}'       => $u ? $u->display_name : '',
			'{course}'     => get_the_title( (int) $row['course_id'] ),
			'{date}'       => date_i18n( get_option( 'date_format' ), strtotime( (string) $row['completed_at'] ) ),
			'{code}'       => (string) $row['certificate'],
			'{verify_url}' => self::url( (string) $row['certificate'] ),
		);
	}

	/** @internal Show the certificate. */
	public static function _route() {
		if ( ! isset( $_GET['fw_mbr_cert'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$row = self::find( sanitize_text_field( wp_unslash( $_GET['fw_mbr_cert'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $row ) {
			wp_die( esc_html__( 'No certificate has this code.', 'fw' ), '', array( 'response' => 404 ) );
		}
		$page = (int) get_post_meta( (int) $row['course_id'], '_fw_certificate_page', true );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			// Show the designed page, filled in.
			self::$current = $row;
			global $wp_query, $post;
			$wp_query = new WP_Query( array( 'page_id' => $page ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			$wp_query->the_post();
			$wp_query->rewind_posts();
			add_filter( 'body_class', function ( $c ) {
				$c[] = 'fw-mbr-certificate-page';
				return $c;
			} );
			add_action( 'wp_head', function () {
				echo '<meta name="robots" content="noindex, nofollow" /><style>@media print{body.fw-mbr-certificate-page header,body.fw-mbr-certificate-page footer,body.fw-mbr-certificate-page .fw-mbr-cert-bar,#wpadminbar{display:none!important}}.fw-mbr-cert-bar{max-width:900px;margin:16px auto;padding:10px 16px;border-radius:8px;background:#ecfdf5;color:#065f46;font:15px/1.5 system-ui,sans-serif;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}</style>';
			} );
			include get_page_template() ? get_page_template() : get_index_template();
			exit;
		}
		$v = self::values( $row );
		nocache_headers();
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( sprintf( __( 'Certificate: %s', 'fw' ), $v['{course}'] ) ); ?></title>
<style>body{margin:0;background:#f3f4f6;font:16px/1.5 Georgia,serif;color:#1f2937}.sheet{max-width:900px;margin:24px auto;background:#fff;padding:64px;text-align:center;border:12px double #c9a227;box-sizing:border-box;aspect-ratio:1.414}h1{font-size:44px;margin:0 0 8px;letter-spacing:.04em}.name{font-size:36px;margin:24px 0 8px;font-style:italic}.course{font-size:24px;margin:8px 0 32px}.meta{font:13px/1.6 system-ui,sans-serif;color:#6b7280}.actions{text-align:center;margin:0 0 24px;font:15px system-ui,sans-serif}@media print{body{background:#fff}.sheet{margin:0;border-width:10px}.actions{display:none}}</style>
</head><body>
<div class="sheet">
	<p class="meta"><?php echo esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?></p>
	<h1><?php esc_html_e( 'Certificate of completion', 'fw' ); ?></h1>
	<p><?php esc_html_e( 'This certifies that', 'fw' ); ?></p>
	<p class="name"><?php echo esc_html( $v['{name}'] ); ?></p>
	<p><?php esc_html_e( 'has completed the course', 'fw' ); ?></p>
	<p class="course"><strong><?php echo esc_html( $v['{course}'] ); ?></strong></p>
	<p class="meta"><?php echo esc_html( $v['{date}'] ); ?> · <?php echo esc_html( sprintf( __( 'Certificate %s', 'fw' ), $v['{code}'] ) ); ?><br /><?php echo esc_html( $v['{verify_url}'] ); ?></p>
</div>
<p class="actions">✓ <?php esc_html_e( 'Verified: issued by this site.', 'fw' ); ?> <button type="button" onclick="window.print()"><?php esc_html_e( 'Print or save as PDF', 'fw' ); ?></button></p>
</body></html>
		<?php
		exit;
	}

	/** @internal Fill the placeholders on the designed page, and add the verified line. */
	public static function _fill( $content ) {
		if ( ! self::$current || ! in_the_loop() ) {
			return $content;
		}
		$v    = self::values( self::$current );
		$bar  = '<div class="fw-mbr-cert-bar"><span>✓ ' . esc_html( sprintf( __( 'Verified certificate %1$s, issued to %2$s.', 'fw' ), $v['{code}'], $v['{name}'] ) ) . '</span><button type="button" onclick="window.print()">' . esc_html__( 'Print or save as PDF', 'fw' ) . '</button></div>';
		$safe = array_map( 'esc_html', $v );
		return $bar . strtr( $content, $safe );
	}

	/** @internal */
	public static function _emails( $c ) {
		$c['certificate'] = array(
			'label'   => __( 'Course certificate', 'fw' ),
			'to'      => 'member',
			'when'    => __( 'When a student completes a course that gives a certificate.', 'fw' ),
			'subject' => __( 'Your certificate for {course}', 'fw' ),
			'body'    => __( "Congratulations, {first_name}!\n\nYou have completed {course}. Here is your certificate (you can print it or save it as PDF):\n\n{certificate_url}", 'fw' ),
		);
		return $c;
	}

	/** @internal */
	public static function _box() {
		add_meta_box( 'fw-mbr-certificate', __( 'Certificate', 'fw' ), array( __CLASS__, '_render_box' ), FW_Membership_Courses::COURSE, 'side', 'default' );
	}

	/** @internal */
	public static function _render_box( $post ) {
		$on   = (bool) get_post_meta( $post->ID, '_fw_certificate', true );
		$page = (int) get_post_meta( $post->ID, '_fw_certificate_page', true );
		wp_nonce_field( 'fw_mbr_cert', '_fw_mbr_cert_nonce' );
		?>
		<p><label><input type="checkbox" name="fw_certificate" value="1" <?php checked( $on ); ?> /> <?php esc_html_e( 'Give a certificate when the course is completed', 'fw' ); ?></label></p>
		<p><label for="fw-cert-page"><?php esc_html_e( 'Design (a page)', 'fw' ); ?></label><br />
			<?php
			wp_dropdown_pages( array( 'name' => 'fw_certificate_page', 'id' => 'fw-cert-page', 'selected' => $page, 'show_option_none' => __( 'The built-in design', 'fw' ), 'option_none_value' => 0 ) );
			?>
		</p>
		<p class="description"><?php esc_html_e( 'Design a page in the builder using {name}, {course}, {date}, {code} and {verify_url}; they are filled in for each student.', 'fw' ); ?></p>
		<?php
	}

	/** @internal */
	public static function _save( $post_id, $post ) {
		if ( FW_Membership_Courses::COURSE !== $post->post_type || ! isset( $_POST['_fw_mbr_cert_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_fw_mbr_cert_nonce'] ) ), 'fw_mbr_cert' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_fw_certificate', empty( $_POST['fw_certificate'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_fw_certificate_page', (int) ( $_POST['fw_certificate_page'] ?? 0 ) );
	}
}
