<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Progress: enrolment, completed lessons, resume, and the course and lesson pages.
 *
 *   fw_mbr_course_enrollments  user_id + course_id, enrolled_at, completed_at, last_lesson_id
 *   fw_mbr_course_progress     user_id + lesson_id (+ course_id), completed_at
 *
 * A student is enrolled the first time they open a lesson they may see. On a lesson
 * page: a progress bar, "Mark complete" (or the quiz, when it has one), and previous /
 * next. A lesson stays closed (with the reason) until its drip day, or until the quiz
 * of a lesson before it that gates the rest is passed. The course page lists the
 * outline with ticks and a "Start" / "Resume" button. When every lesson is complete the
 * course is complete (certificate, if set).
 */
class FW_Membership_Progress {

	const VERSION = '1.0.0';

	/** @var string|null Why the current lesson is closed (for the page). */
	public static $locked = null;

	public static function init() {
		FW_Membership_Installer::module_tables( 'courses', self::VERSION, array( __CLASS__, 'schema' ), array( 'course_enrollments', 'course_progress' ) );
		add_action( 'template_redirect', array( __CLASS__, '_enroll_on_view' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, '_post' ), 3 );
		add_filter( 'the_content', array( __CLASS__, '_lesson_page' ), 9990 );
		add_filter( 'the_content', array( __CLASS__, '_course_page' ), 9990 );
		add_action( 'unysonplus_membership_account_sections', array( __CLASS__, '_account' ), 4 );
	}

	/** @internal */
	public static function schema( $collate ) {
		$e = FW_Membership_Installer::table( 'course_enrollments' );
		$p = FW_Membership_Installer::table( 'course_progress' );
		return array(
			"CREATE TABLE {$e} (
	user_id bigint(20) unsigned NOT NULL default 0,
	course_id bigint(20) unsigned NOT NULL default 0,
	enrolled_at datetime default NULL,
	completed_at datetime default NULL,
	last_lesson_id bigint(20) unsigned NOT NULL default 0,
	certificate varchar(40) NOT NULL default '',
	PRIMARY KEY  (user_id,course_id),
	KEY course (course_id)
) {$collate};",
			"CREATE TABLE {$p} (
	user_id bigint(20) unsigned NOT NULL default 0,
	lesson_id bigint(20) unsigned NOT NULL default 0,
	course_id bigint(20) unsigned NOT NULL default 0,
	completed_at datetime default NULL,
	PRIMARY KEY  (user_id,lesson_id),
	KEY user_course (user_id,course_id)
) {$collate};",
		);
	}

	private static function t( $n ) {
		return FW_Membership_Installer::table( $n );
	}

	/** @return array|null The enrolment row. */
	public static function enrollment( $user_id, $course_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'course_enrollments' ) . ' WHERE user_id = %d AND course_id = %d', (int) $user_id, (int) $course_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function enroll( $user_id, $course_id ) {
		global $wpdb;
		if ( ! $user_id || self::enrollment( $user_id, $course_id ) ) {
			return;
		}
		$wpdb->insert( self::t( 'course_enrollments' ), array( 'user_id' => (int) $user_id, 'course_id' => (int) $course_id, 'enrolled_at' => current_time( 'mysql' ) ) );
		/** A student started a course. */
		do_action( 'unysonplus_membership_course_enrolled', (int) $user_id, (int) $course_id );
	}

	/** @return int[] The lessons a user completed in a course. */
	public static function completed( $user_id, $course_id ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT lesson_id FROM ' . self::t( 'course_progress' ) . ' WHERE user_id = %d AND course_id = %d', (int) $user_id, (int) $course_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @return int Percent complete. */
	public static function percent( $user_id, $course_id ) {
		$all = wp_list_pluck( FW_Membership_Courses::lessons( $course_id ), 'ID' );
		return $all ? (int) round( 100 * count( array_intersect( $all, self::completed( $user_id, $course_id ) ) ) / count( $all ) ) : 0;
	}

	/**
	 * Mark a lesson complete (and the course, when it was the last).
	 *
	 * @return true|WP_Error
	 */
	public static function complete( $user_id, $lesson_id ) {
		global $wpdb;
		$course = (int) wp_get_post_parent_id( $lesson_id );
		if ( ! $course || ! $user_id ) {
			return new WP_Error( 'fw_mbr_course', __( 'That lesson is not part of a course.', 'fw' ) );
		}
		if ( self::why_locked( $user_id, $lesson_id ) ) {
			return new WP_Error( 'fw_mbr_course_locked', __( 'This lesson is not open yet.', 'fw' ) );
		}
		if ( class_exists( 'FW_Membership_Quizzes' ) && FW_Membership_Quizzes::questions( $lesson_id ) && ! FW_Membership_Quizzes::passed( $user_id, $lesson_id ) ) {
			return new WP_Error( 'fw_mbr_course_quiz', __( 'Pass the quiz to complete this lesson.', 'fw' ) );
		}
		self::enroll( $user_id, $course );
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::t( 'course_progress' ) . ' (user_id, lesson_id, course_id, completed_at) VALUES (%d, %d, %d, %s)', (int) $user_id, (int) $lesson_id, $course, current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		do_action( 'unysonplus_membership_lesson_completed', (int) $user_id, (int) $lesson_id, $course );
		$e = self::enrollment( $user_id, $course );
		if ( 100 === self::percent( $user_id, $course ) && $e && ! $e['completed_at'] ) {
			$wpdb->update( self::t( 'course_enrollments' ), array( 'completed_at' => current_time( 'mysql' ) ), array( 'user_id' => (int) $user_id, 'course_id' => $course ) );
			/** A student completed every lesson of a course. */
			do_action( 'unysonplus_membership_course_completed', (int) $user_id, $course );
		}
		return true;
	}

	/**
	 * Why a lesson is closed to a student who may otherwise see it, or null.
	 *
	 * @return string|null
	 */
	public static function why_locked( $user_id, $lesson_id ) {
		$course = (int) wp_get_post_parent_id( $lesson_id );
		if ( ! $course || user_can( (int) $user_id, 'edit_post', $lesson_id ) ) {
			return null;
		}
		$days = (int) get_post_meta( $lesson_id, '_fw_drip_days', true );
		if ( $days > 0 ) {
			$e     = self::enrollment( $user_id, $course );
			$start = $e ? strtotime( $e['enrolled_at'] ) : current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			$opens = $start + $days * DAY_IN_SECONDS;
			if ( $opens > current_time( 'timestamp' ) ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
				return sprintf( __( 'This lesson opens on %s.', 'fw' ), date_i18n( get_option( 'date_format' ), $opens ) );
			}
		}
		if ( class_exists( 'FW_Membership_Quizzes' ) ) {
			foreach ( FW_Membership_Courses::lessons( $course ) as $l ) {
				if ( (int) $l->ID === (int) $lesson_id ) {
					break;
				}
				if ( FW_Membership_Quizzes::gates( $l->ID ) && ! FW_Membership_Quizzes::passed( $user_id, $l->ID ) ) {
					return sprintf( __( 'Pass the quiz in “%s” first.', 'fw' ), get_the_title( $l ) );
				}
			}
		}
		return null;
	}

	/** @return array { prev: WP_Post|null, next: WP_Post|null } */
	public static function neighbours( $lesson_id ) {
		$list = FW_Membership_Courses::lessons( (int) wp_get_post_parent_id( $lesson_id ) );
		$ids  = wp_list_pluck( $list, 'ID' );
		$i    = array_search( (int) $lesson_id, array_map( 'intval', $ids ), true );
		return array( 'prev' => false !== $i && $i > 0 ? $list[ $i - 1 ] : null, 'next' => false !== $i && isset( $list[ $i + 1 ] ) ? $list[ $i + 1 ] : null );
	}

	/** @return WP_Post|null Where to resume: the last lesson opened, else the first not completed. */
	public static function resume_lesson( $user_id, $course_id ) {
		$lessons = FW_Membership_Courses::lessons( $course_id );
		$done    = self::completed( $user_id, $course_id );
		foreach ( $lessons as $l ) {
			if ( ! in_array( (int) $l->ID, $done, true ) ) {
				return $l;
			}
		}
		return $lessons ? $lessons[0] : null;
	}

	/* ---------------------------------------------------------------------- */

	/** @internal Opening a lesson you may see enrols you and remembers where you are. */
	public static function _enroll_on_view() {
		if ( ! is_singular( FW_Membership_Courses::LESSON ) || ! is_user_logged_in() ) {
			return;
		}
		$lesson = get_queried_object();
		$course = (int) $lesson->post_parent;
		if ( ! $course || ! FW_Membership_Access::can( $lesson ) ) {
			return;
		}
		$uid = get_current_user_id();
		self::enroll( $uid, $course );
		global $wpdb;
		$wpdb->update( self::t( 'course_enrollments' ), array( 'last_lesson_id' => (int) $lesson->ID ), array( 'user_id' => $uid, 'course_id' => $course ) );
		self::$locked = self::why_locked( $uid, $lesson->ID );
	}

	/** @internal "Mark complete". */
	public static function _post() {
		if ( empty( $_POST['fw_mbr_complete'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( FW_Membership_Courses::NONCE );
		$lesson = (int) $_POST['fw_mbr_complete'];
		if ( ! FW_Membership_Access::can( $lesson ) ) {
			return;
		}
		self::complete( get_current_user_id(), $lesson );
		$n = self::neighbours( $lesson );
		wp_safe_redirect( $n['next'] && ! empty( $_POST['go_next'] ) ? get_permalink( $n['next'] ) : get_permalink( $lesson ) );
		exit;
	}

	/** @internal The lesson page around the lesson. */
	public static function _lesson_page( $content ) {
		if ( ! is_singular( FW_Membership_Courses::LESSON ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		$lesson = get_post();
		$course = (int) $lesson->post_parent;
		if ( ! $course || ! FW_Membership_Access::can( $lesson ) ) {
			return $content; // the access engine already shows the teaser and prompt
		}
		FW_Membership_Denied::enqueue();
		$uid  = get_current_user_id();
		$pct  = $uid ? self::percent( $uid, $course ) : 0;
		$head = '<nav class="fw-mbr-lesson__crumbs"><a href="' . esc_url( get_permalink( $course ) ) . '">' . esc_html( get_the_title( $course ) ) . '</a>'
			. ( '' !== (string) get_post_meta( $lesson->ID, '_fw_module', true ) ? ' <span aria-hidden="true">›</span> ' . esc_html( (string) get_post_meta( $lesson->ID, '_fw_module', true ) ) : '' ) . '</nav>'
			. ( $uid ? self::bar( $pct ) : '' );
		if ( null !== self::$locked ) {
			return $head . '<div class="fw-mbr-gate"><p class="fw-mbr-gate__title">' . esc_html__( 'Not open yet', 'fw' ) . '</p><p>' . esc_html( self::$locked ) . '</p></div>';
		}
		$n    = self::neighbours( $lesson->ID );
		$done = $uid && in_array( (int) $lesson->ID, self::completed( $uid, $course ), true );
		$foot = '';
		if ( class_exists( 'FW_Membership_Quizzes' ) && FW_Membership_Quizzes::questions( $lesson->ID ) ) {
			$foot .= FW_Membership_Quizzes::render( $lesson->ID, $uid );
		}
		$foot .= '<div class="fw-mbr-lesson__nav">';
		$foot .= $n['prev'] ? '<a class="fw-mbr-lesson__prev" href="' . esc_url( get_permalink( $n['prev'] ) ) . '">← ' . esc_html( get_the_title( $n['prev'] ) ) . '</a>' : '<span></span>';
		if ( $uid && ! $done && ( ! class_exists( 'FW_Membership_Quizzes' ) || ! FW_Membership_Quizzes::questions( $lesson->ID ) || FW_Membership_Quizzes::passed( $uid, $lesson->ID ) ) ) {
			$foot .= '<form method="post" class="fw-mbr-lesson__complete">' . wp_nonce_field( FW_Membership_Courses::NONCE, '_wpnonce', true, false ) . '<input type="hidden" name="fw_mbr_complete" value="' . (int) $lesson->ID . '" />' . ( $n['next'] ? '<input type="hidden" name="go_next" value="1" />' : '' ) . '<button type="submit" class="' . esc_attr( FW_Membership_Settings::button_class() ) . '">' . esc_html( $n['next'] ? __( 'Complete and continue', 'fw' ) : __( 'Complete the course', 'fw' ) ) . '</button></form>';
		} elseif ( $done ) {
			$foot .= '<span class="fw-mbr-lesson__done">✓ ' . esc_html__( 'Completed', 'fw' ) . '</span>';
		}
		$foot .= $n['next'] ? '<a class="fw-mbr-lesson__next" href="' . esc_url( get_permalink( $n['next'] ) ) . '">' . esc_html( get_the_title( $n['next'] ) ) . ' →</a>' : '<span></span>';
		$foot .= '</div>';
		return $head . $content . $foot;
	}

	/** @return string A progress bar. */
	public static function bar( $pct ) {
		return '<div class="fw-mbr-progress" role="progressbar" aria-valuenow="' . (int) $pct . '" aria-valuemin="0" aria-valuemax="100" aria-label="' . esc_attr__( 'Course progress', 'fw' ) . '"><span style="width:' . (int) $pct . '%"></span><em>' . esc_html( sprintf( __( '%d%% complete', 'fw' ), $pct ) ) . '</em></div>';
	}

	/**
	 * The outline with ticks and a Start / Resume button.
	 *
	 * @param int $course_id
	 *
	 * @return string
	 */
	public static function outline_html( $course_id ) {
		FW_Membership_Denied::enqueue();
		$uid   = get_current_user_id();
		$can   = FW_Membership_Courses::can_take( $course_id, $uid );
		$done  = $uid ? self::completed( $uid, $course_id ) : array();
		$total = count( FW_Membership_Courses::lessons( $course_id ) );
		$html  = '<div class="fw-mbr-course">';
		if ( $uid && $can && $total ) {
			$pct   = self::percent( $uid, $course_id );
			$next  = self::resume_lesson( $uid, $course_id );
			$html .= self::bar( $pct );
			if ( $next && $pct < 100 ) {
				$html .= '<p><a class="' . esc_attr( FW_Membership_Settings::button_class() ) . '" href="' . esc_url( get_permalink( $next ) ) . '">' . esc_html( $done ? __( 'Resume the course', 'fw' ) : __( 'Start the course', 'fw' ) ) . '</a></p>';
			}
		} elseif ( ! $can ) {
			$html .= self::join_html( $course_id );
		}
		foreach ( FW_Membership_Courses::outline( $course_id ) as $mod ) {
			if ( '' !== $mod['title'] ) {
				$html .= '<h3 class="fw-mbr-course__module">' . esc_html( $mod['title'] ) . '</h3>';
			}
			$html .= '<ol class="fw-mbr-course__lessons">';
			foreach ( $mod['lessons'] as $l ) {
				$tick  = in_array( (int) $l->ID, $done, true );
				$lock  = $uid && $can ? self::why_locked( $uid, $l->ID ) : null;
				$html .= '<li class="' . ( $tick ? 'is-done' : '' ) . ( $lock ? ' is-locked' : '' ) . '">'
					. '<span class="fw-mbr-course__mark" aria-hidden="true">' . ( $tick ? '✓' : ( $lock || ! $can ? '🔒' : '' ) ) . '</span>'
					. ( $can && ! $lock ? '<a href="' . esc_url( get_permalink( $l ) ) . '">' . esc_html( get_the_title( $l ) ) . '</a>' : '<span>' . esc_html( get_the_title( $l ) ) . '</span>' )
					. ( $tick ? '<span class="screen-reader-text">' . esc_html__( '(completed)', 'fw' ) . '</span>' : '' )
					. '</li>';
			}
			$html .= '</ol>';
		}
		return $html . '</div>';
	}

	/** @return string How to get into a course: buy it, or the plans that include it. */
	private static function join_html( $course_id ) {
		$a     = FW_Membership_Courses::access( $course_id );
		$html  = '<div class="fw-mbr-gate">';
		$names = array();
		foreach ( $a['plans'] as $pid ) {
			$p = FW_Membership_Plans::get( $pid );
			if ( $p ) {
				$names[] = $p['name'];
			}
		}
		$html .= '<p class="fw-mbr-gate__title">' . esc_html( 'logged_in' === $a['who'] ? __( 'Log in to take this course', 'fw' ) : __( 'Join to take this course', 'fw' ) ) . '</p>';
		if ( $names ) {
			$html .= '<p class="fw-mbr-gate__plans">' . esc_html__( 'Included in', 'fw' ) . ' <span class="fw-mbr-gate__plan">' . implode( '</span> <span class="fw-mbr-gate__plan">', array_map( 'esc_html', $names ) ) . '</span></p>';
		}
		$buttons = '';
		$item    = class_exists( 'FW_Membership_Single_Purchase' ) ? FW_Membership_Single_Purchase::plan_for( 'post', $course_id ) : null;
		if ( $item ) {
			$url = FW_Membership_Gateways::checkout_url( array( 'plan' => $item['slug'], 'price' => $item['prices'][0]['id'] ) );
			if ( '' !== $url ) {
				$buttons .= '<a class="' . esc_attr( FW_Membership_Settings::button_class() ) . '" href="' . esc_url( $url ) . '">' . esc_html( sprintf( __( 'Buy this course for %s', 'fw' ), FW_Membership_Plans::money( $item['prices'][0]['amount'], $item['prices'][0]['currency'] ) ) ) . '</a> ';
			}
		}
		if ( ! is_user_logged_in() ) {
			$buttons .= '<a class="' . esc_attr( FW_Membership_Settings::button_class( '' !== $buttons ) ) . '" href="' . esc_url( FW_Membership_Settings::login_url( get_permalink( $course_id ) ) ) . '">' . esc_html__( 'Log in', 'fw' ) . '</a>';
		}
		return $html . ( '' !== $buttons ? '<p class="fw-mbr-gate__actions">' . $buttons . '</p>' : '' ) . '</div>';
	}

	/** @internal The course page: its description, then the outline. */
	public static function _course_page( $content ) {
		if ( ! is_singular( FW_Membership_Courses::COURSE ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		return $content . self::outline_html( get_the_ID() );
	}

	/** @internal "Your courses" on the account page. */
	public static function _account( $user ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'course_enrollments' ) . ' WHERE user_id = %d ORDER BY enrolled_at DESC', $user->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $rows ) {
			return;
		}
		?>
		<section class="fw-mbr-account__section" aria-labelledby="fw-mbr-acc-courses">
			<h2 id="fw-mbr-acc-courses"><?php esc_html_e( 'Your courses', 'fw' ); ?></h2>
			<ul class="fw-mbr-account__payments">
				<?php foreach ( $rows as $r ) : ?>
					<?php if ( 'publish' !== get_post_status( (int) $r['course_id'] ) ) { continue; } ?>
					<li>
						<a href="<?php echo esc_url( get_permalink( (int) $r['course_id'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $r['course_id'] ) ); ?></a>
						<span><?php echo esc_html( sprintf( __( '%d%% complete', 'fw' ), self::percent( $user->ID, (int) $r['course_id'] ) ) ); ?></span>
						<?php if ( '' !== $r['certificate'] && class_exists( 'FW_Membership_Certificates' ) ) : ?>
							<a href="<?php echo esc_url( FW_Membership_Certificates::url( $r['certificate'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Certificate', 'fw' ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}
}
