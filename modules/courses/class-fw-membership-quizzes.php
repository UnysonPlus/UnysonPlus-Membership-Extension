<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Quizzes on lessons. Written in the lesson's Quiz box as plain text:
 *
 *   Q: The question?
 *   * a right answer
 *   - a wrong answer
 *
 *   One "*" is a single choice; several make "tick all that apply" (all and only the
 *   right ones must be ticked); "True" / "False" answers make a true-or-false question;
 *   lines starting "=" are accepted typed answers (case and spaces do not matter).
 *
 * Lesson meta: _fw_quiz (the text), _fw_quiz_pass (pass mark %), _fw_quiz_gate (later
 * lessons stay closed until it is passed). Attempts: table fw_mbr_quiz_attempts. A
 * passed quiz lets the student complete the lesson.
 */
class FW_Membership_Quizzes {

	const VERSION = '1.0.0';
	const NONCE   = 'fw_mbr_quiz';

	/** @var array|null The result just graded (shown on the page). */
	public static $result = null;

	public static function init() {
		FW_Membership_Installer::module_tables( 'quizzes', self::VERSION, array( __CLASS__, 'schema' ), array( 'quiz_attempts' ) );
		add_action( 'template_redirect', array( __CLASS__, '_submit' ), 3 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, '_box' ) );
			add_action( 'save_post_' . FW_Membership_Courses::LESSON, array( __CLASS__, '_save' ), 25, 2 );
		}
	}

	/** @internal */
	public static function schema( $collate ) {
		$t = FW_Membership_Installer::table( 'quiz_attempts' );
		return array( "CREATE TABLE {$t} (
	id bigint(20) unsigned NOT NULL auto_increment,
	user_id bigint(20) unsigned NOT NULL default 0,
	lesson_id bigint(20) unsigned NOT NULL default 0,
	score tinyint(3) unsigned NOT NULL default 0,
	passed tinyint(1) unsigned NOT NULL default 0,
	answers longtext,
	created_at datetime default NULL,
	PRIMARY KEY  (id),
	KEY user_lesson (user_id,lesson_id)
) {$collate};" );
	}

	/**
	 * Read the quiz text.
	 *
	 * @param string $text
	 *
	 * @return array[] [ { q, type single|multiple|truefalse|text, answers: [ { text, right } ], accepted: string[] } ]
	 */
	public static function parse( $text ) {
		$out = array();
		$cur = null;
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^Q\s*[:.]\s*(.+)$/i', $line, $m ) ) {
				if ( $cur ) {
					$out[] = $cur;
				}
				$cur = array( 'q' => $m[1], 'answers' => array(), 'accepted' => array() );
			} elseif ( $cur && preg_match( '/^([*\-])\s*(.+)$/', $line, $m ) ) {
				$cur['answers'][] = array( 'text' => $m[2], 'right' => '*' === $m[1] );
			} elseif ( $cur && preg_match( '/^=\s*(.+)$/', $line, $m ) ) {
				$cur['accepted'][] = $m[1];
			}
		}
		if ( $cur ) {
			$out[] = $cur;
		}
		foreach ( $out as $i => $q ) {
			$rights = count( array_filter( $q['answers'], function ( $a ) {
				return $a['right'];
			} ) );
			$texts  = array_map( 'strtolower', wp_list_pluck( $q['answers'], 'text' ) );
			if ( $q['accepted'] && ! $q['answers'] ) {
				$out[ $i ]['type'] = 'text';
			} elseif ( 2 === count( $texts ) && ! array_diff( $texts, array( 'true', 'false' ) ) ) {
				$out[ $i ]['type'] = 'truefalse';
			} else {
				$out[ $i ]['type'] = $rights > 1 ? 'multiple' : 'single';
			}
		}
		return array_values( array_filter( $out, function ( $q ) {
			return $q['answers'] || $q['accepted'];
		} ) );
	}

	/** @return array[] A lesson's questions. */
	public static function questions( $lesson_id ) {
		return self::parse( (string) get_post_meta( (int) $lesson_id, '_fw_quiz', true ) );
	}

	/** @return bool Does the lesson's quiz keep later lessons closed until passed? */
	public static function gates( $lesson_id ) {
		return (bool) get_post_meta( (int) $lesson_id, '_fw_quiz_gate', true ) && self::questions( $lesson_id );
	}

	/** @return int The pass mark (%). */
	public static function pass_mark( $lesson_id ) {
		$p = (int) get_post_meta( (int) $lesson_id, '_fw_quiz_pass', true );
		return $p > 0 ? min( 100, $p ) : 70;
	}

	/** @return bool Has the user passed this lesson's quiz? */
	public static function passed( $user_id, $lesson_id ) {
		global $wpdb;
		$t = FW_Membership_Installer::table( 'quiz_attempts' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE user_id = %d AND lesson_id = %d AND passed = 1 LIMIT 1", (int) $user_id, (int) $lesson_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Mark answers.
	 *
	 * @param array[] $questions
	 * @param array   $answers question index => chosen index | index[] | typed text
	 *
	 * @return array { score (%), right: bool[] }
	 */
	public static function grade( array $questions, array $answers ) {
		$right = array();
		foreach ( $questions as $i => $q ) {
			$given = $answers[ $i ] ?? null;
			if ( 'text' === $q['type'] ) {
				$norm      = function ( $s ) {
					return strtolower( preg_replace( '/\s+/', ' ', trim( (string) $s ) ) );
				};
				$right[ $i ] = null !== $given && in_array( $norm( $given ), array_map( $norm, $q['accepted'] ), true );
				continue;
			}
			$want = array_keys( array_filter( $q['answers'], function ( $a ) {
				return $a['right'];
			} ) );
			$got  = array_map( 'intval', (array) $given );
			sort( $want );
			sort( $got );
			$right[ $i ] = null !== $given && $want === $got;
		}
		$score = $questions ? (int) round( 100 * count( array_filter( $right ) ) / count( $questions ) ) : 0;
		return array( 'score' => $score, 'right' => $right );
	}

	/** @internal A quiz was submitted. */
	public static function _submit() {
		if ( empty( $_POST['fw_mbr_quiz'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( self::NONCE );
		$lesson = (int) $_POST['fw_mbr_quiz'];
		if ( ! FW_Membership_Access::can( $lesson ) || FW_Membership_Progress::why_locked( get_current_user_id(), $lesson ) ) {
			return;
		}
		$answers = array();
		foreach ( (array) ( $_POST['a'] ?? array() ) as $i => $v ) {
			$answers[ (int) $i ] = is_array( $v ) ? array_map( 'intval', $v ) : sanitize_text_field( wp_unslash( $v ) );
		}
		$res    = self::grade( self::questions( $lesson ), $answers );
		$passed = $res['score'] >= self::pass_mark( $lesson );
		global $wpdb;
		$wpdb->insert( FW_Membership_Installer::table( 'quiz_attempts' ), array( 'user_id' => get_current_user_id(), 'lesson_id' => $lesson, 'score' => $res['score'], 'passed' => $passed ? 1 : 0, 'answers' => wp_json_encode( $answers ), 'created_at' => current_time( 'mysql' ) ) );
		self::$result = array_merge( $res, array( 'passed' => $passed, 'answers' => $answers ) );
		/** A quiz was taken. */
		do_action( 'unysonplus_membership_quiz_taken', get_current_user_id(), $lesson, $res['score'], $passed );
	}

	/**
	 * The quiz form (or the result).
	 *
	 * @param int $lesson_id
	 * @param int $user_id
	 *
	 * @return string
	 */
	public static function render( $lesson_id, $user_id ) {
		$qs     = self::questions( $lesson_id );
		$res    = self::$result;
		$passed = $user_id && self::passed( $user_id, $lesson_id );
		ob_start();
		?>
		<section class="fw-mbr-quiz" aria-labelledby="fw-mbr-quiz-h">
			<h2 id="fw-mbr-quiz-h"><?php esc_html_e( 'Quiz', 'fw' ); ?></h2>
			<?php if ( $res ) : ?>
				<p class="fw-mbr-notice fw-mbr-notice--<?php echo $res['passed'] ? 'success' : 'error'; ?>" role="status"><?php echo esc_html( $res['passed'] ? sprintf( __( 'Passed with %d%%. Well done!', 'fw' ), $res['score'] ) : sprintf( __( 'You scored %1$d%%; %2$d%% passes. Have another go.', 'fw' ), $res['score'], self::pass_mark( $lesson_id ) ) ); ?></p>
			<?php elseif ( $passed ) : ?>
				<p class="fw-mbr-notice fw-mbr-notice--success"><?php esc_html_e( 'You passed this quiz.', 'fw' ); ?></p>
			<?php else : ?>
				<p class="fw-mbr-muted"><?php echo esc_html( sprintf( __( '%1$d questions; %2$d%% passes.', 'fw' ), count( $qs ), self::pass_mark( $lesson_id ) ) ); ?></p>
			<?php endif; ?>
			<?php if ( ! $passed || ( $res && ! $res['passed'] ) ) : ?>
				<form method="post" class="fw-mbr-form">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="fw_mbr_quiz" value="<?php echo (int) $lesson_id; ?>" />
					<?php foreach ( $qs as $i => $q ) : ?>
						<?php $mark = $res ? ( $res['right'][ $i ] ? ' is-right' : ' is-wrong' ) : ''; ?>
						<fieldset class="fw-mbr-quiz__q<?php echo esc_attr( $mark ); ?>">
							<legend><?php echo esc_html( ( $i + 1 ) . '. ' . $q['q'] ); ?><?php echo 'multiple' === $q['type'] ? ' <small>' . esc_html__( '(tick all that apply)', 'fw' ) . '</small>' : ''; ?></legend>
							<?php if ( 'text' === $q['type'] ) : ?>
								<input type="text" name="a[<?php echo (int) $i; ?>]" aria-label="<?php esc_attr_e( 'Your answer', 'fw' ); ?>" />
							<?php else : ?>
								<?php foreach ( $q['answers'] as $j => $a ) : ?>
									<label><input type="<?php echo 'multiple' === $q['type'] ? 'checkbox' : 'radio'; ?>" name="a[<?php echo (int) $i; ?>]<?php echo 'multiple' === $q['type'] ? '[]' : ''; ?>" value="<?php echo (int) $j; ?>" /> <?php echo esc_html( $a['text'] ); ?></label>
								<?php endforeach; ?>
							<?php endif; ?>
						</fieldset>
					<?php endforeach; ?>
					<p class="fw-mbr-form__actions"><button type="submit" class="<?php echo esc_attr( FW_Membership_Settings::button_class() ); ?>"><?php esc_html_e( 'Check my answers', 'fw' ); ?></button></p>
				</form>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** @internal */
	public static function _box() {
		add_meta_box( 'fw-mbr-quiz', __( 'Quiz', 'fw' ), array( __CLASS__, '_render_box' ), FW_Membership_Courses::LESSON, 'normal', 'default' );
	}

	/** @internal */
	public static function _render_box( $post ) {
		$text = (string) get_post_meta( $post->ID, '_fw_quiz', true );
		$qs   = self::parse( $text );
		wp_nonce_field( self::NONCE, '_fw_mbr_quiz_nonce' );
		?>
		<p class="description"><?php esc_html_e( 'Write one question per block. "Q:" starts a question; "*" marks a right answer and "-" a wrong one (several "*" = tick all that apply; True / False answers = true or false); "=" lines are accepted typed answers. Leave it empty for no quiz.', 'fw' ); ?></p>
		<textarea name="fw_quiz" rows="10" class="large-text code" placeholder="<?php echo esc_attr( "Q: What does a plan decide?\n* Who can see what\n- The site's colours\n\nQ: Type the word drip.\n= drip" ); ?>"><?php echo esc_textarea( $text ); ?></textarea>
		<p>
			<label><?php esc_html_e( 'Pass mark', 'fw' ); ?> <input type="number" min="1" max="100" name="fw_quiz_pass" value="<?php echo (int) self::pass_mark( $post->ID ); ?>" style="width:5em" />%</label>
			&nbsp; <label><input type="checkbox" name="fw_quiz_gate" value="1" <?php checked( (bool) get_post_meta( $post->ID, '_fw_quiz_gate', true ) ); ?> /> <?php esc_html_e( 'Later lessons stay closed until it is passed', 'fw' ); ?></label>
		</p>
		<?php if ( $qs ) : ?>
			<p class="description"><?php echo esc_html( sprintf( _n( 'Read as %d question.', 'Read as %d questions.', count( $qs ), 'fw' ), count( $qs ) ) ); ?></p>
		<?php endif; ?>
		<?php
	}

	/** @internal */
	public static function _save( $post_id, $post ) {
		if ( ! isset( $_POST['_fw_mbr_quiz_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_fw_mbr_quiz_nonce'] ) ), self::NONCE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_fw_quiz', sanitize_textarea_field( wp_unslash( $_POST['fw_quiz'] ?? '' ) ) );
		update_post_meta( $post_id, '_fw_quiz_pass', max( 1, min( 100, (int) ( $_POST['fw_quiz_pass'] ?? 70 ) ) ) );
		update_post_meta( $post_id, '_fw_quiz_gate', empty( $_POST['fw_quiz_gate'] ) ? 0 : 1 );
	}
}
