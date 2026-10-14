<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The gates. Protection applies everywhere protected content can surface, not only on
 * its own page — most membership leaks come from the places nobody tested:
 *
 *   the requested page           template_redirect: redirect / login / 404, or a flag
 *                                for the content filter to show the teaser + prompt
 *   the content itself           the_content (priority 9999), so page-builder output is
 *                                replaced too; in loops, every post is judged on its own
 *   excerpts                     get_the_excerpt: the teaser, never the full text
 *   lists, search and archives   the_posts: items whose rule says "hide" are removed
 *   RSS feeds                    the_content_feed / the_excerpt_rss
 *   the REST API                 rest_prepare_{post_type}: content replaced by the prompt
 *   comments                     closed and empty for people without access
 *   attachments                  judged by the item they are attached to
 *   sitemaps                     hidden items are left out
 *   archives, the whole site,    template_redirect against the page's context
 *   URL patterns
 *
 * Every decision comes from FW_Membership_Access.
 */
class FW_Membership_Protect {

	/** @var array|false|null The denial for the requested item (false: allowed; null: not judged yet). */
	private static $main_denied = null;

	/** @var int The requested item, once judged. */
	private static $main_post_id = 0;

	public static function init() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, '_action_template_redirect' ), 5 );
		add_filter( 'the_content', array( __CLASS__, '_filter_content' ), 9999 );
		add_filter( 'get_the_excerpt', array( __CLASS__, '_filter_excerpt' ), 9999, 2 );
		add_filter( 'the_posts', array( __CLASS__, '_filter_the_posts' ), 10, 2 );
		add_filter( 'the_content_feed', array( __CLASS__, '_filter_feed' ), 9999 );
		add_filter( 'the_excerpt_rss', array( __CLASS__, '_filter_feed' ), 9999 );
		add_filter( 'comments_open', array( __CLASS__, '_filter_comments_open' ), 20, 2 );
		add_filter( 'pings_open', array( __CLASS__, '_filter_comments_open' ), 20, 2 );
		add_filter( 'comments_array', array( __CLASS__, '_filter_comments_array' ), 20, 2 );
		add_filter( 'get_comments_number', array( __CLASS__, '_filter_comments_number' ), 20, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, '_filter_sitemap_args' ) );
		// The SEO extension builds descriptions, social tags and structured data from the
		// post's text before the content filter runs: give it the teaser, not the body.
		add_filter( 'fw_seo_post_text', array( __CLASS__, '_filter_seo_text' ), 20, 2 );
		add_action( 'rest_api_init', array( __CLASS__, '_action_rest_init' ) );
	}

	/**
	 * The request path relative to the site (so /blog/x on a site installed in a
	 * subfolder is still /blog/x).
	 *
	 * @return string
	 */
	public static function request_path() {
		$uri  = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $base && '/' !== $base && 0 === strpos( $uri, rtrim( $base, '/' ) ) ) {
			$uri = substr( $uri, strlen( rtrim( $base, '/' ) ) );
		}
		return '/' . ltrim( $uri, '/' );
	}

	/* ---------------------------------------------------------------------- *
	 * The requested page
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _action_template_redirect() {
		if ( is_feed() || is_robots() || is_favicon() || is_trackback() ) {
			return;
		}
		$path = self::request_path();

		if ( is_singular() ) {
			$post   = get_queried_object();
			$denied = $post instanceof WP_Post ? self::denied_here( $post ) : null;
			if ( $denied || ( $post instanceof WP_Post && FW_Membership_Access::is_protected( $post, $path ) ) ) {
				FW_Membership_Cache::no_cache();
			}
			if ( $denied ) {
				self::act( $denied, true );
			}
			return;
		}

		// Archives, the blog, search, the front page of posts, any other URL.
		$ctx = array( 'path' => $path );
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$ctx['terms'] = array( (int) $term->term_id );
			}
		} elseif ( is_post_type_archive() ) {
			$pt = get_query_var( 'post_type' );
			$ctx['archive'] = is_array( $pt ) ? reset( $pt ) : (string) $pt;
		}
		$denied = FW_Membership_Access::denied_for_context( $ctx );
		if ( $denied ) {
			FW_Membership_Cache::no_cache();
			self::act( $denied, false );
		}
	}

	/**
	 * Carry out a rule's "what everyone else sees".
	 *
	 * @param array $denied   { rule, specificity }
	 * @param bool  $singular On one item, "prompt" shows the teaser + prompt in place of the
	 *                        content; elsewhere a logged-out visitor is sent to log in.
	 */
	private static function act( array $denied, $singular ) {
		/** Someone was turned away from a page (the Security module logs it). */
		do_action( 'unysonplus_membership_access_denied', $denied, $singular ? get_queried_object_id() : 0 );
		$d       = $denied['rule']['denied'];
		$persona = FW_Membership_Access::persona();
		$here    = home_url( add_query_arg( array() ) );

		switch ( $d['action'] ) {
			case 'redirect':
				$to = (string) $d['redirect'];
				if ( '' !== $to && untrailingslashit( $to ) !== untrailingslashit( $here ) ) {
					wp_redirect( $to, 302, 'Unyson+ Membership' ); // phpcs:ignore WordPress.Security.SafeRedirect -- an administrator chose this address.
					exit;
				}
				break;

			case 'not_found':
				// As if it did not exist: clear the found posts and the queried object too,
				// or anything reading them later (the SEO description, social tags, the
				// theme's 404 template running a loop) would still print the content.
				global $wp_query, $post;
				$wp_query->set_404();
				$wp_query->posts             = array();
				$wp_query->post_count        = 0;
				$wp_query->found_posts       = 0;
				$wp_query->post              = null;
				$wp_query->queried_object    = null;
				$wp_query->queried_object_id = 0;
				$post                        = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				status_header( 404 );
				FW_Membership_Cache::no_cache();
				return;

			case 'login':
				if ( ! $persona['logged_in'] && '' === $persona['preview'] ) {
					wp_safe_redirect( wp_login_url( $here ) );
					exit;
				}
				break;

			default: // prompt
				if ( ! $singular && ! $persona['logged_in'] && '' === $persona['preview'] ) {
					wp_safe_redirect( wp_login_url( $here ) );
					exit;
				}
		}
		FW_Membership_Denied::enqueue();
	}

	/* ---------------------------------------------------------------------- *
	 * Content, excerpts, lists, feeds
	 * ---------------------------------------------------------------------- */

	/**
	 * @param WP_Post|null $post
	 *
	 * @return array|null
	 */
	private static function denied_here( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		// The requested page is judged with its address too (URL rules), whenever it is
		// asked about — the SEO head is built before template_redirect runs. Anything else
		// in a loop is judged on its own.
		// Once judged, the requested item is remembered by id: a "not found" denial clears
		// the query, after which is_singular() no longer recognises it (the SEO tags are
		// printed after that).
		if ( self::$main_post_id && self::$main_post_id === (int) $post->ID ) {
			return self::$main_denied ? self::$main_denied : null;
		}
		if ( ! is_admin() && did_action( 'wp' ) && is_singular() && get_queried_object_id() === (int) $post->ID ) {
			self::$main_post_id = (int) $post->ID;
			$denied             = FW_Membership_Access::denied_for_post( $post, self::request_path() );
			/**
			 * The verdict for the page being viewed (decided once per request). The metered
			 * paywall returns null here to let a free read through.
			 */
			$denied             = $denied ? apply_filters( 'unysonplus_membership_main_denied', $denied, $post ) : $denied;
			self::$main_denied  = $denied ? $denied : false;
			return $denied;
		}
		return FW_Membership_Access::denied_for_post( $post );
	}

	/** @internal */
	public static function _filter_content( $content ) {
		if ( FW_Membership_Denied::$rendering ) {
			return $content;
		}
		$post   = get_post();
		$denied = self::denied_here( $post );

		return $denied ? FW_Membership_Denied::render_for_post( $post, $denied ) : $content;
	}

	/** @internal */
	public static function _filter_excerpt( $excerpt, $post = null ) {
		if ( FW_Membership_Denied::$rendering ) {
			return $excerpt;
		}
		$post   = get_post( $post );
		$denied = self::denied_here( $post );
		if ( ! $denied ) {
			return $excerpt;
		}
		return FW_Membership_Denied::teaser_text( $post, max( 20, (int) $denied['rule']['denied']['teaser_words'] ) );
	}

	/** @internal */
	public static function _filter_seo_text( $text, $post ) {
		$post   = get_post( $post );
		$denied = $post ? self::denied_here( $post ) : null;
		if ( ! $denied ) {
			return $text;
		}
		if ( 'prompt' !== $denied['rule']['denied']['action'] ) {
			return ''; // not found, redirect or login: nothing about it should be public
		}
		return FW_Membership_Denied::teaser_text( $post, (int) $denied['rule']['denied']['teaser_words'] );
	}

	/** @internal */
	public static function _filter_feed( $content ) {
		$post   = get_post();
		$denied = self::denied_here( $post );
		if ( ! $denied ) {
			return $content;
		}
		$teaser = FW_Membership_Denied::teaser_text( $post, max( 20, (int) $denied['rule']['denied']['teaser_words'] ) );
		return esc_html( $teaser ) . ' <a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html__( 'Members can read the rest on the site.', 'fw' ) . '</a>';
	}

	/**
	 * Lists, search and archives: drop items whose rule says "hide from lists" for
	 * people without access. (The single-item request itself is handled on
	 * template_redirect, so it is never dropped here.)
	 *
	 * @internal
	 *
	 * @param WP_Post[] $posts
	 * @param WP_Query  $query
	 *
	 * @return WP_Post[]
	 */
	public static function _filter_the_posts( $posts, $query ) {
		if ( ! $posts || ( is_admin() && ! wp_doing_ajax() ) || ( $query->is_main_query() && $query->is_singular() ) ) {
			return $posts;
		}
		$keep = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post || ! FW_Membership_Access::is_protected( $post ) ) {
				$keep[] = $post;
				continue;
			}
			$denied = FW_Membership_Access::denied_for_post( $post );
			if ( $denied && ( ! empty( $denied['rule']['denied']['hide'] ) || 'not_found' === $denied['rule']['denied']['action'] ) ) {
				continue;
			}
			$keep[] = $post;
		}
		return $keep;
	}

	/* ---------------------------------------------------------------------- *
	 * Comments, sitemaps, REST
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _filter_comments_open( $open, $post_id ) {
		return $open && ! FW_Membership_Access::denied_for_post( $post_id );
	}

	/** @internal */
	public static function _filter_comments_array( $comments, $post_id ) {
		return FW_Membership_Access::denied_for_post( $post_id ) ? array() : $comments;
	}

	/** @internal */
	public static function _filter_comments_number( $count, $post_id ) {
		return $post_id && FW_Membership_Access::denied_for_post( $post_id ) ? 0 : $count;
	}

	/**
	 * Leave items marked "hide from lists" out of the XML sitemap.
	 *
	 * @internal
	 */
	public static function _filter_sitemap_args( $args ) {
		$hidden = get_posts( array(
			'post_type'        => 'any',
			'post_status'      => 'publish',
			'fields'           => 'ids',
			'posts_per_page'   => 1000,
			'meta_key'         => FW_Membership_Access::META_HIDE, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			'suppress_filters' => true,
		) );
		if ( $hidden ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), $hidden );
		}
		return $args;
	}

	/** @internal */
	public static function _action_rest_init() {
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $pt ) {
			add_filter( 'rest_prepare_' . $pt, array( __CLASS__, '_filter_rest_prepare' ), 20, 3 );
		}
	}

	/**
	 * @internal
	 *
	 * @param WP_REST_Response $response
	 * @param WP_Post          $post
	 * @param WP_REST_Request  $request
	 *
	 * @return WP_REST_Response
	 */
	public static function _filter_rest_prepare( $response, $post, $request ) {
		$denied = FW_Membership_Access::denied_for_post( $post );
		if ( ! $denied ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['content'] ) ) {
			$data['content'] = array( 'rendered' => FW_Membership_Denied::render_for_post( $post, $denied ), 'protected' => true );
		}
		if ( isset( $data['excerpt'] ) ) {
			$data['excerpt'] = array( 'rendered' => wpautop( esc_html( FW_Membership_Denied::teaser_text( $post, 40 ) ) ), 'protected' => true );
		}
		$data['fw_membership'] = array( 'protected' => true );
		$response->set_data( $data );
		return $response;
	}
}
