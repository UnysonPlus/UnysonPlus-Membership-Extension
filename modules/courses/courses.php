<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Courses module (Membership → Modules → Courses): courses and lessons on the access
 * engine, with progress, quizzes and certificates.
 *
 *   class-fw-membership-courses.php       course / lesson types, structure, who may take it
 *   class-fw-membership-progress.php      enrolment, completion, resume, the course and lesson pages
 *   class-fw-membership-quizzes.php       quizzes that can gate later lessons
 *   class-fw-membership-certificates.php  certificates on completion, with a verification link
 *
 * The Course Outline element (shortcodes/course-outline) shows a course's outline and
 * progress anywhere.
 */

$fw_mbr_course_dir = dirname( __FILE__ );
foreach ( array( 'courses', 'progress', 'quizzes', 'certificates' ) as $fw_mbr_course_part ) {
	require_once $fw_mbr_course_dir . '/class-fw-membership-' . $fw_mbr_course_part . '.php';
}
FW_Membership_Courses::init();
FW_Membership_Progress::init();
FW_Membership_Quizzes::init();
FW_Membership_Certificates::init();

add_filter( 'unysonplus_membership_nav', function ( $nav ) {
	$nav['main']['items']['courses'] = array( 'label' => __( 'Courses', 'fw' ), 'icon' => 'dashicons-welcome-learn-more', 'url' => admin_url( 'edit.php?post_type=' . FW_Membership_Courses::COURSE ) );
	return $nav;
} );
