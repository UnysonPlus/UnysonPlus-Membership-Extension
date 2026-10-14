<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Courses on the access engine: a course is protected content with order and progress,
 * not a second membership system.
 *
 *   fw_course   the course page (public: its description, outline and "join / buy")
 *   fw_lesson   lessons; post_parent = the course, meta _fw_module (module title),
 *               menu_order = position, _fw_drip_days (opens N days after enrolling)
 *
 * Who may take a course (the course's Membership box): members of chosen plans, anyone
 * logged in, or everyone; and it can also be sold on its own (Pay per item). Courses
 * keep one managed access rule (meta _fw_course_rule) covering their lessons (a
 * "pages under" rule on the course), so lessons are locked exactly like any other
 * protected content, in lists, feeds, search and the REST API too.
 */
class FW_Membership_Courses {

	const COURSE = 'fw_course';
	const LESSON = 'fw_lesson';
	const NONCE  = 'fw_mbr_course';

	public static function init() {
		add_action( 'init', array( __CLASS__, '_types' ), 5 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, '_boxes' ) );
			// After the "Sell on its own" box (save_post 20), so a new price is in the rule.
			add_action( 'save_post', array( __CLASS__, '_save_course' ), 30, 2 );
			add_action( 'save_post_' . self::LESSON, array( __CLASS__, '_save_lesson' ), 20, 2 );
			add_filter( 'manage_' . self::LESSON . '_posts_columns', array( __CLASS__, '_columns' ) );
			add_action( 'manage_' . self::LESSON . '_posts_custom_column', array( __CLASS__, '_column' ), 10, 2 );
		}
		add_action( 'before_delete_post', array( __CLASS__, '_deleted' ) );
	}

	/** @internal */
	public static function _types() {
		register_post_type( self::COURSE, array(
			'labels'       => array( 'name' => __( 'Courses', 'fw' ), 'singular_name' => __( 'Course', 'fw' ), 'add_new_item' => __( 'Add a course', 'fw' ), 'edit_item' => __( 'Edit course', 'fw' ), 'all_items' => __( 'Courses', 'fw' ) ),
			'public'       => true,
			'has_archive'  => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-welcome-learn-more',
			'rewrite'      => array( 'slug' => _x( 'courses', 'URL part', 'fw' ) ),
			'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
		) );
		register_post_type( self::LESSON, array(
			'labels'       => array( 'name' => __( 'Lessons', 'fw' ), 'singular_name' => __( 'Lesson', 'fw' ), 'add_new_item' => __( 'Add a lesson', 'fw' ), 'edit_item' => __( 'Edit lesson', 'fw' ), 'all_items' => __( 'Lessons', 'fw' ) ),
			'public'       => true,
			'show_in_menu' => 'edit.php?post_type=' . self::COURSE,
			'show_in_rest' => true,
			'hierarchical' => false,
			'rewrite'      => array( 'slug' => _x( 'lessons', 'URL part', 'fw' ) ),
			'supports'     => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
		) );
		$v = '1';
		if ( get_option( 'fw_ext_membership_courses_rewrite' ) !== $v ) {
			flush_rewrite_rules( false );
			update_option( 'fw_ext_membership_courses_rewrite', $v, true );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Structure
	 * ---------------------------------------------------------------------- */

	/** @return WP_Post[] A course's lessons in order. */
	public static function lessons( $course_id ) {
		return get_posts( array( 'post_type' => self::LESSON, 'post_parent' => (int) $course_id, 'post_status' => 'publish', 'orderby' => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ), 'numberposts' => 500, 'suppress_filters' => true ) );
	}

	/**
	 * Modules with their lessons.
	 *
	 * @return array[] [ { title, lessons: WP_Post[] } ] in order (a lesson without a module goes in an untitled one)
	 */
	public static function outline( $course_id ) {
		$out = array();
		foreach ( self::lessons( $course_id ) as $l ) {
			$m = (string) get_post_meta( $l->ID, '_fw_module', true );
			$k = count( $out ) - 1;
			if ( $k < 0 || $out[ $k ]['title'] !== $m ) {
				$out[] = array( 'title' => $m, 'lessons' => array() );
				$k++;
			}
			$out[ $k ]['lessons'][] = $l;
		}
		return $out;
	}

	/** @return array { who: plans|logged_in|open, plans: int[] } */
	public static function access( $course_id ) {
		$a = (array) get_post_meta( (int) $course_id, '_fw_course_access', true );
		return array( 'who' => in_array( $a['who'] ?? '', array( 'plans', 'logged_in', 'open' ), true ) ? $a['who'] : 'plans', 'plans' => array_map( 'intval', (array) ( $a['plans'] ?? array() ) ) );
	}

	/**
	 * May a user take the course (open its lessons)?
	 *
	 * @param int $course_id
	 * @param int $user_id
	 *
	 * @return bool
	 */
	public static function can_take( $course_id, $user_id ) {
		$lessons = self::lessons( $course_id );
		if ( ! $lessons ) {
			$a = self::access( $course_id );
			return 'open' === $a['who'] || ( $user_id && ( 'logged_in' === $a['who'] || array_intersect( $a['plans'], FW_Membership_Service::user_plans( (int) $user_id ) ) ) );
		}
		return FW_Membership_Access::can( $lessons[0], (int) $user_id ? (int) $user_id : null );
	}

	/**
	 * Keep the course's access rule in step with its settings.
	 *
	 * @param int $course_id
	 */
	public static function sync_rule( $course_id ) {
		$a     = self::access( $course_id );
		$rid   = (int) get_post_meta( $course_id, '_fw_course_rule', true );
		$title = get_the_title( $course_id );
		if ( 'open' === $a['who'] ) {
			if ( $rid ) {
				FW_Membership_Rules::delete( $rid );
				delete_post_meta( $course_id, '_fw_course_rule' );
			}
			return;
		}
		$plans = $a['plans'];
		if ( class_exists( 'FW_Membership_Single_Purchase' ) ) {
			$item = FW_Membership_Single_Purchase::plan_for( 'post', $course_id );
			if ( $item ) {
				$plans[] = (int) $item['id']; // buying the course opens its lessons
			}
		}
		$data = array(
			'name'   => sprintf( __( 'Course: %s', 'fw' ), $title ),
			'status' => 'publish' === get_post_status( $course_id ) ? 'active' : 'inactive',
			'who'    => array( 'plans' => array_values( array_unique( $plans ) ), 'roles' => array(), 'logged_in' => 'logged_in' === $a['who'] ),
			'denied' => array( 'action' => 'prompt', 'teaser_words' => 40, 'message' => sprintf( __( 'This lesson is part of the course %s.', 'fw' ), $title ) ),
		);
		$target = array( array( 'mode' => 'include', 'type' => 'children', 'sub_type' => '', 'object_id' => (int) $course_id, 'pattern' => '' ) );
		$r      = FW_Membership_Rules::save( $data, $target, $rid && FW_Membership_Rules::get( $rid ) ? $rid : 0 );
		if ( ! is_wp_error( $r ) ) {
			update_post_meta( $course_id, '_fw_course_rule', (int) $r );
		}
	}

	/** @internal A deleted course takes its rule with it. */
	public static function _deleted( $post_id ) {
		if ( self::COURSE === get_post_type( $post_id ) ) {
			$rid = (int) get_post_meta( $post_id, '_fw_course_rule', true );
			if ( $rid ) {
				FW_Membership_Rules::delete( $rid );
			}
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Editor boxes
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _boxes() {
		add_meta_box( 'fw-mbr-course', __( 'Who can take this course', 'fw' ), array( __CLASS__, '_course_box' ), self::COURSE, 'side', 'high' );
		add_meta_box( 'fw-mbr-lesson', __( 'Lesson', 'fw' ), array( __CLASS__, '_lesson_box' ), self::LESSON, 'side', 'high' );
		add_meta_box( 'fw-mbr-course-lessons', __( 'Lessons', 'fw' ), array( __CLASS__, '_lessons_box' ), self::COURSE, 'normal', 'default' );
	}

	/** @internal */
	public static function _course_box( $post ) {
		$a = self::access( $post->ID );
		wp_nonce_field( self::NONCE, '_fw_mbr_course_nonce' );
		?>
		<p><label><input type="radio" name="fw_course_who" value="plans" <?php checked( $a['who'], 'plans' ); ?> /> <?php esc_html_e( 'Members of these plans', 'fw' ); ?></label></p>
		<div style="margin:0 0 8px 22px">
			<?php foreach ( FW_Membership_Plans::all( 'active' ) as $p ) : ?>
				<?php if ( FW_Membership_Plans::is_item( $p ) ) { continue; } ?>
				<label style="display:block"><input type="checkbox" name="fw_course_plans[]" value="<?php echo (int) $p['id']; ?>" <?php checked( in_array( (int) $p['id'], $a['plans'], true ) ); ?> /> <?php echo esc_html( $p['name'] ); ?></label>
			<?php endforeach; ?>
		</div>
		<p><label><input type="radio" name="fw_course_who" value="logged_in" <?php checked( $a['who'], 'logged_in' ); ?> /> <?php esc_html_e( 'Anyone logged in (a free course with an account)', 'fw' ); ?></label></p>
		<p><label><input type="radio" name="fw_course_who" value="open" <?php checked( $a['who'], 'open' ); ?> /> <?php esc_html_e( 'Everyone', 'fw' ); ?></label></p>
		<p class="description"><?php esc_html_e( 'The course page itself is always visible (it is where people join). To also sell it on its own, use "Sell on its own" below.', 'fw' ); ?></p>
		<?php
	}

	/** @internal The course's lessons, in order, with a link to add one. */
	public static function _lessons_box( $post ) {
		$outline = self::outline( $post->ID );
		if ( ! $outline ) {
			echo '<p>' . esc_html__( 'No lessons yet.', 'fw' ) . '</p>';
		}
		foreach ( $outline as $mod ) {
			echo '' !== $mod['title'] ? '<h4 style="margin:12px 0 4px">' . esc_html( $mod['title'] ) . '</h4>' : '';
			echo '<ol style="margin-top:0">';
			foreach ( $mod['lessons'] as $l ) {
				echo '<li><a href="' . esc_url( get_edit_post_link( $l->ID ) ) . '">' . esc_html( get_the_title( $l ) ) . '</a></li>';
			}
			echo '</ol>';
		}
		if ( 'auto-draft' !== $post->post_status ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::LESSON . '&fw_course=' . $post->ID ) ) . '">' . esc_html__( 'Add a lesson', 'fw' ) . '</a></p>';
		}
	}

	/** @internal */
	public static function _lesson_box( $post ) {
		$course = (int) $post->post_parent ? (int) $post->post_parent : ( isset( $_GET['fw_course'] ) ? (int) $_GET['fw_course'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		wp_nonce_field( self::NONCE, '_fw_mbr_lesson_nonce' );
		?>
		<p><label for="fw-lesson-course"><strong><?php esc_html_e( 'Course', 'fw' ); ?></strong></label><br />
			<select id="fw-lesson-course" name="fw_lesson_course" style="width:100%">
				<option value="0"><?php esc_html_e( '— choose —', 'fw' ); ?></option>
				<?php foreach ( get_posts( array( 'post_type' => self::COURSE, 'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $c ) : ?>
					<option value="<?php echo (int) $c->ID; ?>" <?php selected( $course, $c->ID ); ?>><?php echo esc_html( get_the_title( $c ) ); ?></option>
				<?php endforeach; ?>
			</select></p>
		<p><label for="fw-lesson-module"><strong><?php esc_html_e( 'Module', 'fw' ); ?></strong></label><br />
			<input type="text" id="fw-lesson-module" name="fw_lesson_module" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, '_fw_module', true ) ); ?>" style="width:100%" placeholder="<?php esc_attr_e( 'Getting started', 'fw' ); ?>" /></p>
		<p><label for="fw-lesson-drip"><strong><?php esc_html_e( 'Opens after (days)', 'fw' ); ?></strong></label><br />
			<input type="number" min="0" id="fw-lesson-drip" name="fw_lesson_drip" value="<?php echo (int) get_post_meta( $post->ID, '_fw_drip_days', true ); ?>" style="width:6em" />
			<span class="description"><?php esc_html_e( 'days after the student starts the course', 'fw' ); ?></span></p>
		<p class="description"><?php esc_html_e( 'Order: the "Order" number under Lesson attributes. Lessons with the same module are shown together.', 'fw' ); ?></p>
		<?php
	}

	/** @internal */
	public static function _save_course( $post_id, $post ) {
		if ( self::COURSE !== $post->post_type || ! isset( $_POST['_fw_mbr_course_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_fw_mbr_course_nonce'] ) ), self::NONCE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$who = sanitize_key( wp_unslash( $_POST['fw_course_who'] ?? 'plans' ) );
		update_post_meta( $post_id, '_fw_course_access', array( 'who' => $who, 'plans' => array_values( array_filter( array_map( 'intval', (array) ( $_POST['fw_course_plans'] ?? array() ) ) ) ) ) );
		self::sync_rule( $post_id );
	}

	/** @internal */
	public static function _save_lesson( $post_id, $post ) {
		if ( ! isset( $_POST['_fw_mbr_lesson_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_fw_mbr_lesson_nonce'] ) ), self::NONCE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_fw_module', sanitize_text_field( wp_unslash( $_POST['fw_lesson_module'] ?? '' ) ) );
		update_post_meta( $post_id, '_fw_drip_days', max( 0, (int) ( $_POST['fw_lesson_drip'] ?? 0 ) ) );
		$course = (int) ( $_POST['fw_lesson_course'] ?? 0 );
		if ( $course !== (int) $post->post_parent ) {
			remove_action( 'save_post_' . self::LESSON, array( __CLASS__, '_save_lesson' ), 20 );
			wp_update_post( array( 'ID' => $post_id, 'post_parent' => $course ) );
			add_action( 'save_post_' . self::LESSON, array( __CLASS__, '_save_lesson' ), 20, 2 );
		}
	}

	/** @internal */
	public static function _columns( $cols ) {
		return array_merge( array_slice( $cols, 0, 2 ), array( 'fw_course' => __( 'Course', 'fw' ), 'fw_module' => __( 'Module', 'fw' ) ), array_slice( $cols, 2 ) );
	}

	/** @internal */
	public static function _column( $col, $post_id ) {
		if ( 'fw_course' === $col ) {
			$c = (int) wp_get_post_parent_id( $post_id );
			echo $c ? '<a href="' . esc_url( get_edit_post_link( $c ) ) . '">' . esc_html( get_the_title( $c ) ) . '</a>' : '—';
		} elseif ( 'fw_module' === $col ) {
			echo esc_html( (string) get_post_meta( $post_id, '_fw_module', true ) );
		}
	}
}
