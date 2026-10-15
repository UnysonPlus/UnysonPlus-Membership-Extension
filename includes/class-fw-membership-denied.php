<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * What someone without access sees instead of the content: a teaser (the start of
 * the content) and a prompt that says who it is for and what to do next.
 *
 * The prompt markup is shared with the Paywall prompt element (shortcodes/paywall-prompt),
 * so a designer can restyle both through the same classes (.fw-mbr-gate*).
 */
class FW_Membership_Denied {

	/** @var bool True while rendering a teaser, so the content filter does not recurse. */
	public static $rendering = false;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/** @internal */
	public static function register_assets() {
		$ext = fw_ext( 'membership' );
		wp_register_style( 'fw-membership-front', $ext->get_uri() . '/static/css/front.css', array(), $ext->manifest->get_version() );
	}

	public static function enqueue() {
		if ( ! wp_style_is( 'fw-membership-front', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'fw-membership-front' );
	}

	/**
	 * The start of an item as plain text: its excerpt if it has one, else what comes
	 * before the More block, else the first $words words of the rendered content
	 * (rendered, so page-builder pages get a real teaser rather than an empty one).
	 *
	 * @param WP_Post $post
	 * @param int     $words 0 for no teaser.
	 *
	 * @return string Plain text.
	 */
	public static function teaser_text( $post, $words ) {
		if ( $words <= 0 ) {
			return '';
		}
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return wp_trim_words( $post->post_excerpt, $words, '…' );
		}
		$raw = (string) $post->post_content;
		if ( preg_match( '/<!--more(.*?)?-->/', $raw, $m ) ) {
			$raw = substr( $raw, 0, strpos( $raw, $m[0] ) );
		}
		self::$rendering = true;
		try {
			$html = do_shortcode( $raw );
		} finally {
			self::$rendering = false;
		}
		return wp_trim_words( wp_strip_all_tags( $html ), $words, '…' );
	}

	/**
	 * The teaser and the prompt, in place of an item's content.
	 *
	 * @param WP_Post $post
	 * @param array   $failing { rule, specificity }
	 *
	 * @return string HTML
	 */
	public static function render_for_post( $post, array $failing ) {
		self::enqueue();
		$teaser = self::teaser_text( $post, (int) $failing['rule']['denied']['teaser_words'] );
		$out    = '<div class="fw-mbr-gated">';
		if ( '' !== $teaser ) {
			$out .= '<div class="fw-mbr-gated__teaser"><p>' . esc_html( $teaser ) . '</p></div>';
		}
		/** Prompt text for this denial (Drip says when it opens; Pay per item offers the item). */
		$out .= self::prompt( $failing['rule'], (array) apply_filters( 'unysonplus_membership_prompt_args', array(), $failing, $post ) );
		$out .= '</div>';

		/** Filters the HTML shown in place of protected content. */
		return (string) apply_filters( 'unysonplus_membership_denied_html', $out, $post, $failing );
	}

	/**
	 * The prompt box.
	 *
	 * @param array|null $rule The rule that was failed (null: a generic prompt).
	 * @param array      $args title, message, show_plans, show_login, button_text, button_link (Paywall prompt element), no_join (no automatic Join button)
	 *
	 * @return string HTML
	 */
	public static function prompt( $rule = null, array $args = array() ) {
		self::enqueue();
		$persona = FW_Membership_Access::persona();
		$in      = $persona['logged_in'];

		// The plans that would unlock it: the rule's plans, or every open plan when the
		// rule asks for "any plan". A rule about roles or logging in lists none.
		$ids = $rule ? $rule['who']['plans'] : array();
		if ( ! $ids && ( ! $rule || ( ! $rule['who']['roles'] && ! $rule['who']['logged_in'] ) ) ) {
			$ids = array_keys( FW_Membership_Plans::all( 'active' ) );
		}
		$plans    = array();
		$open_ids = array();
		foreach ( $ids as $pid ) {
			$p = FW_Membership_Plans::get( $pid );
			if ( $p && 'active' === $p['status'] ) {
				$plans[]    = $p['name'];
				$open_ids[] = (int) $p['id'];
			}
		}
		$needs_login_only = $rule && $rule['who']['logged_in'] && ! $in;

		$title = (string) ( $args['title'] ?? '' );
		if ( '' === $title ) {
			$title = $in
				? __( 'Your account does not include this', 'fw' )
				: ( $needs_login_only ? __( 'Log in to continue', 'fw' ) : __( 'This is for members', 'fw' ) );
		}
		$message = (string) ( $args['message'] ?? '' );
		if ( '' === $message && $rule && '' !== trim( (string) $rule['denied']['message'] ) ) {
			$message = (string) $rule['denied']['message'];
		}
		if ( '' === $message ) {
			$message = $in
				? __( 'It is part of a membership plan you do not hold yet.', 'fw' )
				: ( $needs_login_only ? __( 'This page is for people with an account on this site.', 'fw' ) : __( 'Log in if you are a member, or join to read the rest.', 'fw' ) );
		}

		$show_plans = ! isset( $args['show_plans'] ) || $args['show_plans'];
		$show_login = ( ! isset( $args['show_login'] ) || $args['show_login'] ) && ! $in;
		$here       = ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$btn_text   = (string) ( $args['button_text'] ?? '' );
		$btn_link   = (string) ( $args['button_link'] ?? '' );
		if ( '' === $btn_text && '' === $btn_link && ! $needs_login_only && $open_ids && empty( $args['no_join'] ) ) {
			// No button of its own: offer a way to join (Settings → Join page, or the checkout).
			$btn_link = FW_Membership_Settings::join_url( $open_ids );
			$btn_text = '' === $btn_link ? '' : ( $in ? __( 'See the plans', 'fw' ) : __( 'Join', 'fw' ) );
		}

		ob_start();
		?>
		<div class="fw-mbr-gate" role="region" aria-label="<?php esc_attr_e( 'Members only', 'fw' ); ?>">
			<span class="fw-mbr-gate__icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/></svg>
			</span>
			<p class="fw-mbr-gate__title"><?php echo esc_html( $title ); ?></p>
			<div class="fw-mbr-gate__message"><?php echo wp_kses_post( wpautop( $message ) ); ?></div>
			<?php if ( $show_plans && $plans && ! $needs_login_only ) : ?>
				<p class="fw-mbr-gate__plans">
					<?php echo esc_html( count( $plans ) > 1 ? __( 'Included in:', 'fw' ) : __( 'Included in', 'fw' ) ); ?>
					<?php foreach ( $plans as $name ) : ?>
						<span class="fw-mbr-gate__plan"><?php echo esc_html( $name ); ?></span>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
			<?php if ( $show_login || ( '' !== $btn_text && '' !== $btn_link ) ) : ?>
				<p class="fw-mbr-gate__actions">
					<?php if ( '' !== $btn_text && '' !== $btn_link ) : ?>
						<a class="fw-mbr-gate__button <?php echo esc_attr( FW_Membership_Settings::button_class() ); ?>" href="<?php echo esc_url( $btn_link ); ?>"><?php echo esc_html( $btn_text ); ?></a>
					<?php endif; ?>
					<?php if ( $show_login ) : ?>
						<a class="fw-mbr-gate__button <?php echo esc_attr( FW_Membership_Settings::button_class( '' !== $btn_text && '' !== $btn_link ) ); ?>" href="<?php echo esc_url( FW_Membership_Settings::login_url( $here ) ); ?>"><?php esc_html_e( 'Log in', 'fw' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
