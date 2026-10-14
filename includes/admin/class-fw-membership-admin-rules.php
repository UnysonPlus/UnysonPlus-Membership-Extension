<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Access rules: the list, the editor, their actions, and the item search the editor
 * uses to pick single pages, posts and terms.
 */
class FW_Membership_Admin_Rules {

	public static function init() {
		add_action( 'wp_ajax_fw_mbr_find', array( __CLASS__, '_ajax_find' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Actions
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $action
	 *
	 * @return array Redirect query args.
	 */
	public static function handle( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification -- verified by the admin page before dispatch.
		$r  = wp_unslash( $_REQUEST );
		$id = (int) ( $r['id'] ?? 0 );

		switch ( $action ) {
			case 'save_rule':
				$targets = array();
				foreach ( (array) ( $r['targets'] ?? array() ) as $tg ) {
					if ( ! is_array( $tg ) ) {
						continue;
					}
					$type      = sanitize_key( (string) ( $tg['type'] ?? '' ) );
					$targets[] = array(
						'mode'      => sanitize_key( (string) ( $tg['mode'] ?? 'include' ) ),
						'type'      => $type,
						'sub_type'  => 'term' === $type ? sanitize_key( (string) ( $tg['sub_tax'] ?? '' ) ) : sanitize_key( (string) ( $tg['sub_pt'] ?? '' ) ),
						'object_id' => (int) ( $tg['object_id'] ?? 0 ),
						'pattern'   => (string) ( $tg['pattern'] ?? '' ),
					);
				}
				$res = FW_Membership_Rules::save( array(
					'name'     => (string) ( $r['name'] ?? '' ),
					'status'   => sanitize_key( (string) ( $r['status'] ?? 'active' ) ),
					'priority' => (int) ( $r['priority'] ?? 0 ),
					'drip_days' => (int) ( $r['drip_days'] ?? 0 ),
					'drip_date' => (string) ( $r['drip_date'] ?? '' ),
					'who'      => array(
						'plans'     => (array) ( $r['who_plans'] ?? array() ),
						'roles'     => (array) ( $r['who_roles'] ?? array() ),
						'logged_in' => ! empty( $r['who_logged_in'] ),
						/** Conditions a module adds to "Who can see it" (read from the posted form). */
						'extra'     => (array) apply_filters( 'unysonplus_membership_rule_who_extra', array(), $r ),
					),
					'denied'   => array(
						'action'       => sanitize_key( (string) ( $r['denied_action'] ?? 'prompt' ) ),
						'message'      => (string) ( $r['denied_message'] ?? '' ),
						'redirect'     => (string) ( $r['denied_redirect'] ?? '' ),
						'teaser_words' => (int) ( $r['teaser_words'] ?? 55 ),
						'hide'         => ! empty( $r['denied_hide'] ),
						'metered'      => ! empty( $r['denied_metered'] ),
					),
				), $targets, $id );
				if ( is_wp_error( $res ) ) {
					FW_Membership_Admin_Page::flash( 'error', $res->get_error_message() );
					return $id ? array( 'tab' => 'rules', 'rule' => $id ) : array( 'tab' => 'rules', 'new' => 1 );
				}
				FW_Membership_Admin_Page::flash( 'success', $id ? __( 'Access rule saved.', 'fw' ) : __( 'Access rule created. It applies straight away; use "View as" in the admin bar on the site to check it.', 'fw' ) );
				return array( 'tab' => 'rules', 'rule' => $res );

			case 'delete_rule':
				FW_Membership_Rules::delete( $id );
				FW_Membership_Admin_Page::flash( 'success', __( 'Access rule deleted.', 'fw' ) );
				return array( 'tab' => 'rules' );

			case 'toggle_rule':
				$rule = FW_Membership_Rules::get( $id );
				if ( $rule ) {
					FW_Membership_Rules::set_status( $id, 'active' !== $rule['status'] );
					FW_Membership_Admin_Page::flash( 'success', 'active' !== $rule['status'] ? __( 'Access rule switched on.', 'fw' ) : __( 'Access rule switched off.', 'fw' ) );
				}
				return array( 'tab' => 'rules' );
		}
		// phpcs:enable
		return array( 'tab' => 'rules' );
	}

	/** @internal admin-ajax: search pages, posts or terms for the item picker. */
	public static function _ajax_find() {
		check_ajax_referer( 'fw_mbr_find' );
		if ( ! current_user_can( FW_Membership_Service::capability() ) ) {
			wp_send_json_error( null, 403 );
		}
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : 'post';
		$sub  = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : '';
		$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$out  = array();

		if ( 'term' === $kind ) {
			if ( taxonomy_exists( $sub ) ) {
				$terms = get_terms( array( 'taxonomy' => $sub, 'search' => $q, 'number' => 20, 'hide_empty' => false ) );
				foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
					$out[] = array( 'id' => (int) $t->term_id, 'label' => $t->name );
				}
			}
		} else {
			$types = post_type_exists( $sub ) ? array( $sub ) : array_keys( self::post_types() );
			foreach ( get_posts( array(
				'post_type'        => $types,
				'post_status'      => array( 'publish', 'private', 'draft', 'future' ),
				's'                => $q,
				'posts_per_page'   => 20,
				'orderby'          => '' === $q ? 'date' : 'relevance',
				'suppress_filters' => true,
			) ) as $p ) {
				$out[] = array( 'id' => (int) $p->ID, 'label' => self::post_label( $p ) );
			}
		}
		wp_send_json_success( $out );
	}

	/* ---------------------------------------------------------------------- *
	 * Labels
	 * ---------------------------------------------------------------------- */

	/** @return array slug => plural label, public post types (no attachments). */
	public static function post_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' !== $pt->name ) {
				$out[ $pt->name ] = $pt->labels->name;
			}
		}
		return $out;
	}

	/** @return array slug => label, public taxonomies. */
	public static function taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( 'post_format' !== $tax->name ) {
				$out[ $tax->name ] = $tax->labels->singular_name;
			}
		}
		return $out;
	}

	/**
	 * @param WP_Post $p
	 *
	 * @return string
	 */
	private static function post_label( $p ) {
		$type = get_post_type_object( $p->post_type );
		$t    = '' !== $p->post_title ? $p->post_title : __( '(no title)', 'fw' );
		return $t . ' — ' . ( $type ? $type->labels->singular_name : $p->post_type ) . ( 'publish' !== $p->post_status ? ' (' . $p->post_status . ')' : '' );
	}

	/**
	 * One target in words.
	 *
	 * @param array $t
	 *
	 * @return string
	 */
	public static function target_label( array $t ) {
		$pts  = self::post_types();
		$taxs = self::taxonomies();
		switch ( $t['type'] ) {
			case 'site':
				return __( 'The entire site', 'fw' );
			case 'post_type':
				return sprintf( __( 'All %s', 'fw' ), $pts[ $t['sub_type'] ] ?? $t['sub_type'] );
			case 'archive':
				return sprintf( __( 'The %s archive', 'fw' ), $pts[ $t['sub_type'] ] ?? $t['sub_type'] );
			case 'post':
				$p = get_post( $t['object_id'] );
				return $p ? self::post_label( $p ) : sprintf( __( 'Item #%d (deleted)', 'fw' ), $t['object_id'] );
			case 'children':
				$p = get_post( $t['object_id'] );
				return sprintf( __( 'Pages under %s', 'fw' ), $p ? $p->post_title : '#' . $t['object_id'] );
			case 'term':
				$term = get_term( $t['object_id'] );
				return sprintf( '%1$s: %2$s', $taxs[ $t['sub_type'] ] ?? $t['sub_type'], $term && ! is_wp_error( $term ) ? $term->name : '#' . $t['object_id'] );
			case 'url':
				return sprintf( __( 'Addresses %s', 'fw' ), $t['pattern'] );
		}
		return $t['type'];
	}

	/**
	 * @param array $rule
	 *
	 * @return string
	 */
	public static function who_label( array $rule ) {
		$who   = $rule['who'];
		$parts = array();
		foreach ( $who['plans'] as $pid ) {
			$p = FW_Membership_Plans::get( $pid );
			if ( $p ) {
				$parts[] = $p['name'];
			}
		}
		$names = wp_roles()->get_names();
		foreach ( $who['roles'] as $role ) {
			$parts[] = sprintf( __( 'role: %s', 'fw' ), translate_user_role( $names[ $role ] ?? $role ) );
		}
		if ( $who['logged_in'] ) {
			$parts[] = __( 'anyone logged in', 'fw' );
		}
		return $parts ? ucfirst( implode( ', ', $parts ) ) : __( 'Members of any plan', 'fw' );
	}

	/**
	 * @param array $rule
	 *
	 * @return string
	 */
	public static function denied_label( array $rule ) {
		$d = $rule['denied'];
		switch ( $d['action'] ) {
			case 'login':
				return __( 'Sent to log in', 'fw' );
			case 'redirect':
				return sprintf( __( 'Sent to %s', 'fw' ), $d['redirect'] );
			case 'not_found':
				return __( 'Page not found', 'fw' );
		}
		return $d['teaser_words'] ? __( 'A teaser and a prompt', 'fw' ) : __( 'A prompt', 'fw' );
	}

	/* ---------------------------------------------------------------------- *
	 * List
	 * ---------------------------------------------------------------------- */

	public static function render_list() {
		$rules = FW_Membership_Rules::all();
		$base  = FW_Membership_Admin_Page::get_page_url( 'rules' );

		if ( ! $rules ) {
			FW_Membership_App::empty_state(
				'dashicons-lock',
				__( 'Protect your first content', 'fw' ),
				__( 'An access rule says what is protected (the whole site, a post type, single pages, a category, an address), who may see it, and what everyone else sees instead. You can also protect a single page from its own Membership box in the editor.', 'fw' ),
				array( array( __( 'New access rule', 'fw' ), add_query_arg( 'new', 1, $base ), true ) )
			);
			return;
		}
		?>
		<div class="fw-mbr-listbar">
			<p class="fw-mbr-muted"><?php esc_html_e( 'Every rule that covers a page must be passed to see it. Administrators always see everything; use "View as" in the admin bar on the site to check what others see.', 'fw' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'new', 1, $base ) ); ?>"><?php esc_html_e( 'New access rule', 'fw' ); ?></a>
		</div>
		<table class="wp-list-table widefat fixed striped fw-mbr-rules-table">
			<thead>
				<tr>
					<th scope="col" class="column-primary"><?php esc_html_e( 'Rule', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Protects', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Who can see it', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Everyone else sees', 'fw' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rules as $rule ) : ?>
					<?php $edit = add_query_arg( 'rule', $rule['id'], $base ); ?>
					<tr<?php echo 'active' !== $rule['status'] ? ' class="fw-mbr-is-off"' : ''; ?>>
						<td class="column-primary" data-colname="<?php esc_attr_e( 'Rule', 'fw' ); ?>">
							<strong><a class="row-title" href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $rule['name'] ); ?></a></strong>
							<?php if ( 'active' !== $rule['status'] ) : ?>
								<span class="fw-mbr-mstatus fw-mbr-mstatus--expired"><?php esc_html_e( 'Off', 'fw' ); ?></span>
							<?php endif; ?>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'fw' ); ?></a> | </span>
								<span><a href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'toggle_rule', array( 'id' => $rule['id'] ), 'rules' ) ); ?>"><?php echo 'active' === $rule['status'] ? esc_html__( 'Switch off', 'fw' ) : esc_html__( 'Switch on', 'fw' ); ?></a> | </span>
								<span class="trash"><a href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'delete_rule', array( 'id' => $rule['id'] ), 'rules' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this access rule? What it protects becomes open.', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete', 'fw' ); ?></a></span>
							</div>
						</td>
						<td data-colname="<?php esc_attr_e( 'Protects', 'fw' ); ?>">
							<?php
							$in  = array();
							$out = array();
							foreach ( $rule['targets'] as $t ) {
								if ( 'exclude' === $t['mode'] ) {
									$out[] = self::target_label( $t );
								} else {
									$in[] = self::target_label( $t );
								}
							}
							echo esc_html( implode( '; ', $in ) );
							if ( $out ) {
								echo '<br /><span class="fw-mbr-muted">' . esc_html( sprintf( __( 'except %s', 'fw' ), implode( '; ', $out ) ) ) . '</span>';
							}
							?>
						</td>
						<td data-colname="<?php esc_attr_e( 'Who can see it', 'fw' ); ?>"><?php echo esc_html( self::who_label( $rule ) ); ?></td>
						<td data-colname="<?php esc_attr_e( 'Everyone else sees', 'fw' ); ?>"><?php echo esc_html( self::denied_label( $rule ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Editor
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $id 0 for a new rule.
	 */
	public static function render_edit( $id ) {
		$rule = $id ? FW_Membership_Rules::get( $id ) : null;
		if ( $id && ! $rule ) {
			FW_Membership_App::empty_state( 'dashicons-warning', __( 'Access rule not found', 'fw' ), __( 'It may have been deleted.', 'fw' ), array( array( __( 'All access rules', 'fw' ), FW_Membership_Admin_Page::get_page_url( 'rules' ) ) ) );
			return;
		}
		$rule = $rule ? $rule : array(
			'id'       => 0,
			'name'     => '',
			'status'   => 'active',
			'priority' => 0,
			'who'      => array( 'plans' => array(), 'roles' => array(), 'logged_in' => false, 'extra' => array() ), 'drip_days' => 0, 'drip_date' => '',
			'denied'   => array( 'action' => 'prompt', 'message' => '', 'redirect' => '', 'teaser_words' => 55, 'hide' => false ),
			'targets'  => array( array( 'mode' => 'include', 'type' => 'post_type', 'sub_type' => 'post', 'object_id' => 0, 'pattern' => '' ) ),
		);
		$pts   = self::post_types();
		$taxs  = self::taxonomies();
		$plans = FW_Membership_Plans::all();
		$types = array(
			'post_type' => __( 'All items of a type', 'fw' ),
			'post'      => __( 'A specific page or post', 'fw' ),
			'children'  => __( 'Pages under a page', 'fw' ),
			'term'      => __( 'Items in a category or tag', 'fw' ),
			'archive'   => __( 'A post type archive', 'fw' ),
			'url'       => __( 'Addresses (URL)', 'fw' ),
			'site'      => __( 'The entire site', 'fw' ),
		);
		$rows   = $rule['targets'];
		$rows[] = null; // template row
		?>
		<form method="post" class="fw-mbr-rule-form" id="fw-mbr-rule-form" action="<?php echo esc_url( FW_Membership_Admin_Page::get_page_url( 'rules' ) ); ?>"
		      data-find="<?php echo esc_url( add_query_arg( array( 'action' => 'fw_mbr_find', '_ajax_nonce' => wp_create_nonce( 'fw_mbr_find' ) ), admin_url( 'admin-ajax.php' ) ) ); ?>">
			<?php FW_Membership_Admin_Page::form_fields( 'save_rule' ); ?>
			<input type="hidden" name="id" value="<?php echo (int) $rule['id']; ?>" />

			<div class="fw-mbr-record">
				<div class="fw-mbr-record__main">
					<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-rname">
						<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-rname"><?php esc_html_e( 'Rule', 'fw' ); ?></h2></header>
						<p class="fw-mbr-field">
							<label for="fw-mbr-rname"><?php esc_html_e( 'Name', 'fw' ); ?></label>
							<input type="text" id="fw-mbr-rname" name="name" required value="<?php echo esc_attr( $rule['name'] ); ?>" placeholder="<?php esc_attr_e( 'Members-only articles', 'fw' ); ?>" />
						</p>
					</section>

					<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-what">
						<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-what"><?php esc_html_e( 'What it protects', 'fw' ); ?></h2></header>
						<p class="fw-mbr-muted"><?php esc_html_e( 'Add as many lines as you need. "Except" lines carve exceptions out of the rest, such as every post except one free sample.', 'fw' ); ?></p>
						<ul class="fw-mbr-targets" id="fw-mbr-targets">
							<?php foreach ( $rows as $i => $t ) : ?>
								<?php
								$tpl = null === $t;
								$t   = $t ? $t : array( 'mode' => 'include', 'type' => 'post_type', 'sub_type' => 'post', 'object_id' => 0, 'pattern' => '' );
								$n   = $tpl ? '__i__' : $i;
								$nm  = 'targets[' . $n . ']';
								$lbl = in_array( $t['type'], array( 'post', 'children', 'term' ), true ) && $t['object_id'] ? self::target_label( $t ) : '';
								?>
								<li class="fw-mbr-target<?php echo $tpl ? ' fw-mbr-target--tpl' : ''; ?>" data-type="<?php echo esc_attr( $t['type'] ); ?>"<?php echo $tpl ? ' hidden' : ''; ?>>
									<select name="<?php echo esc_attr( $nm ); ?>[mode]" aria-label="<?php esc_attr_e( 'Include or except', 'fw' ); ?>"<?php disabled( $tpl ); ?>>
										<option value="include" <?php selected( $t['mode'], 'include' ); ?>><?php esc_html_e( 'Protect', 'fw' ); ?></option>
										<option value="exclude" <?php selected( $t['mode'], 'exclude' ); ?>><?php esc_html_e( 'Except', 'fw' ); ?></option>
									</select>
									<select class="fw-mbr-target__type" name="<?php echo esc_attr( $nm ); ?>[type]" aria-label="<?php esc_attr_e( 'What', 'fw' ); ?>"<?php disabled( $tpl ); ?>>
										<?php foreach ( $types as $k => $label ) : ?>
											<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $t['type'], $k ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<select class="fw-mbr-target__pt" name="<?php echo esc_attr( $nm ); ?>[sub_pt]" aria-label="<?php esc_attr_e( 'Post type', 'fw' ); ?>"<?php disabled( $tpl ); ?>>
										<?php foreach ( $pts as $k => $label ) : ?>
											<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $t['sub_type'], $k ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<select class="fw-mbr-target__tax" name="<?php echo esc_attr( $nm ); ?>[sub_tax]" aria-label="<?php esc_attr_e( 'Taxonomy', 'fw' ); ?>"<?php disabled( $tpl ); ?>>
										<?php foreach ( $taxs as $k => $label ) : ?>
											<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $t['sub_type'], $k ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<span class="fw-mbr-pick">
										<input type="hidden" class="fw-mbr-pick__id" name="<?php echo esc_attr( $nm ); ?>[object_id]" value="<?php echo (int) $t['object_id']; ?>"<?php disabled( $tpl ); ?> />
										<input type="search" class="fw-mbr-pick__q" value="<?php echo esc_attr( $lbl ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'fw' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Find the item', 'fw' ); ?>"<?php disabled( $tpl ); ?> />
										<ul class="fw-mbr-pick__results" role="listbox" hidden></ul>
									</span>
									<input type="text" class="fw-mbr-target__url" name="<?php echo esc_attr( $nm ); ?>[pattern]" value="<?php echo esc_attr( $t['pattern'] ); ?>" placeholder="/members/*" aria-label="<?php esc_attr_e( 'Address pattern', 'fw' ); ?>"<?php disabled( $tpl ); ?> />
									<button type="button" class="button-link fw-mbr-target__remove" aria-label="<?php esc_attr_e( 'Remove this line', 'fw' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
								</li>
							<?php endforeach; ?>
						</ul>
						<p><button type="button" class="button" id="fw-mbr-target-add"><?php esc_html_e( 'Add a line', 'fw' ); ?></button></p>
						<p class="description"><?php esc_html_e( 'An address pattern ending in * covers everything under it: /members/* protects /members/ and every page below it.', 'fw' ); ?></p>
					</section>

					<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-else">
						<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-else"><?php esc_html_e( 'What everyone else sees', 'fw' ); ?></h2></header>
						<fieldset class="fw-mbr-choices">
							<legend class="screen-reader-text"><?php esc_html_e( 'What everyone else sees', 'fw' ); ?></legend>
							<label><input type="radio" name="denied_action" value="prompt" <?php checked( $rule['denied']['action'], 'prompt' ); ?> /> <?php esc_html_e( 'A teaser and a prompt to log in or join (recommended)', 'fw' ); ?></label>
							<span class="fw-mbr-inline">
								<label for="fw-mbr-teaser"><?php esc_html_e( 'Teaser length', 'fw' ); ?></label>
								<input type="number" id="fw-mbr-teaser" name="teaser_words" min="0" max="500" value="<?php echo (int) $rule['denied']['teaser_words']; ?>" />
								<span class="fw-mbr-muted"><?php esc_html_e( 'words (0 for none)', 'fw' ); ?></span>
							</span>
							<label><input type="radio" name="denied_action" value="login" <?php checked( $rule['denied']['action'], 'login' ); ?> /> <?php esc_html_e( 'Send logged-out visitors to log in, then back', 'fw' ); ?></label>
							<label><input type="radio" name="denied_action" value="redirect" <?php checked( $rule['denied']['action'], 'redirect' ); ?> /> <?php esc_html_e( 'Send them to another page', 'fw' ); ?></label>
							<span class="fw-mbr-inline">
								<label class="screen-reader-text" for="fw-mbr-redirect"><?php esc_html_e( 'Address', 'fw' ); ?></label>
								<input type="url" id="fw-mbr-redirect" name="denied_redirect" class="regular-text" value="<?php echo esc_attr( $rule['denied']['redirect'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/join/' ) ); ?>" />
							</span>
							<label><input type="radio" name="denied_action" value="not_found" <?php checked( $rule['denied']['action'], 'not_found' ); ?> /> <?php esc_html_e( 'Show "page not found", as if it did not exist', 'fw' ); ?></label>
						</fieldset>
						<p class="fw-mbr-field">
							<label for="fw-mbr-message"><?php esc_html_e( 'Message in the prompt (optional)', 'fw' ); ?></label>
							<textarea id="fw-mbr-message" name="denied_message" rows="3"><?php echo esc_textarea( $rule['denied']['message'] ); ?></textarea>
						</p>
						<label class="fw-mbr-check-line"><input type="checkbox" name="denied_hide" value="1" <?php checked( $rule['denied']['hide'] ); ?> /> <?php esc_html_e( 'Also hide it from lists, archives and search for people without access', 'fw' ); ?></label>
					</section>
				</div>

				<div class="fw-mbr-record__side">
					<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-who">
						<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-who"><?php esc_html_e( 'Who can see it', 'fw' ); ?></h2></header>
						<fieldset class="fw-mbr-choices">
							<legend class="screen-reader-text"><?php esc_html_e( 'Plans', 'fw' ); ?></legend>
							<?php if ( ! $plans ) : ?>
								<p class="fw-mbr-muted"><?php esc_html_e( 'No plans yet. Create one under Plans.', 'fw' ); ?></p>
							<?php endif; ?>
							<?php foreach ( $plans as $p ) : ?>
								<label><input type="checkbox" name="who_plans[]" value="<?php echo (int) $p['id']; ?>" <?php checked( in_array( $p['id'], $rule['who']['plans'], true ) ); ?> /> <?php echo esc_html( $p['name'] ); ?></label>
							<?php endforeach; ?>
							<label><input type="checkbox" name="who_logged_in" value="1" <?php checked( $rule['who']['logged_in'] ); ?> /> <?php esc_html_e( 'Anyone logged in (no plan needed)', 'fw' ); ?></label>
						</fieldset>
						<details class="fw-mbr-more">
							<summary><?php esc_html_e( 'Roles', 'fw' ); ?></summary>
							<fieldset class="fw-mbr-choices">
								<legend class="screen-reader-text"><?php esc_html_e( 'Roles', 'fw' ); ?></legend>
								<?php foreach ( wp_roles()->get_names() as $slug => $name ) : ?>
									<label><input type="checkbox" name="who_roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $rule['who']['roles'], true ) ); ?> /> <?php echo esc_html( translate_user_role( $name ) ); ?></label>
								<?php endforeach; ?>
							</fieldset>
						</details>
						<?php
						/** More "Who can see it" choices (the CRM connection adds tags, lists and segments). */
						do_action( 'unysonplus_membership_rule_who_fields', $rule );
						?>
						<p class="description"><?php esc_html_e( 'Passing any one ticked choice is enough. With nothing ticked, members of any plan can see it. A plan also counts for the plans it includes.', 'fw' ); ?></p>
					</section>

					<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-ropts">
						<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-ropts"><?php esc_html_e( 'Options', 'fw' ); ?></h2></header>
						<div class="fw-mbr-fields fw-mbr-fields--stack">
							<p class="fw-mbr-field">
								<label for="fw-mbr-rstatus"><?php esc_html_e( 'Status', 'fw' ); ?></label>
								<select id="fw-mbr-rstatus" name="status">
									<option value="active" <?php selected( $rule['status'], 'active' ); ?>><?php esc_html_e( 'On', 'fw' ); ?></option>
									<option value="inactive" <?php selected( $rule['status'], 'inactive' ); ?>><?php esc_html_e( 'Off', 'fw' ); ?></option>
								</select>
							</p>
							<p class="fw-mbr-field">
								<label for="fw-mbr-rpriority"><?php esc_html_e( 'Priority', 'fw' ); ?></label>
								<input type="number" id="fw-mbr-rpriority" name="priority" value="<?php echo (int) $rule['priority']; ?>" aria-describedby="fw-mbr-rpriority-d" />
								<span class="description" id="fw-mbr-rpriority-d"><?php esc_html_e( 'When two rules lock the same page equally specifically, the higher number decides what visitors see.', 'fw' ); ?></span>
							</p>
							<?php
							/** More rule options (Drip: opens after N days or on a date; Metered paywall: free reads). */
							do_action( 'unysonplus_membership_rule_option_fields', $rule );
							?>
						</div>
					</section>

					<p class="fw-mbr-actions">
						<button type="submit" class="button button-primary button-large"><?php echo $rule['id'] ? esc_html__( 'Save rule', 'fw' ) : esc_html__( 'Create rule', 'fw' ); ?></button>
						<a class="button button-large" href="<?php echo esc_url( FW_Membership_Admin_Page::get_page_url( 'rules' ) ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
					</p>
				</div>
			</div>
		</form>
		<script>
		( function () {
			var form = document.getElementById( 'fw-mbr-rule-form' );
			var list = document.getElementById( 'fw-mbr-targets' );
			if ( ! form || ! list ) { return; }
			var tpl = list.querySelector( '.fw-mbr-target--tpl' );
			var next = list.children.length;
			var NEEDS = { post_type: [ 'pt' ], archive: [ 'pt' ], post: [ 'pt', 'pick' ], children: [ 'pick' ], term: [ 'tax', 'pick' ], url: [ 'url' ], site: [] };

			function sync( row ) {
				var type = row.querySelector( '.fw-mbr-target__type' ).value;
				var need = NEEDS[ type ] || [];
				row.dataset.type = type;
				row.querySelector( '.fw-mbr-target__pt' ).hidden = need.indexOf( 'pt' ) < 0;
				row.querySelector( '.fw-mbr-target__tax' ).hidden = need.indexOf( 'tax' ) < 0;
				row.querySelector( '.fw-mbr-pick' ).hidden = need.indexOf( 'pick' ) < 0;
				row.querySelector( '.fw-mbr-target__url' ).hidden = need.indexOf( 'url' ) < 0;
			}
			function clearPick( row ) {
				row.querySelector( '.fw-mbr-pick__id' ).value = '0';
				row.querySelector( '.fw-mbr-pick__q' ).value = '';
			}
			list.querySelectorAll( '.fw-mbr-target:not(.fw-mbr-target--tpl)' ).forEach( sync );

			document.getElementById( 'fw-mbr-target-add' ).addEventListener( 'click', function () {
				var row = tpl.cloneNode( true );
				row.hidden = false;
				row.classList.remove( 'fw-mbr-target--tpl' );
				row.querySelectorAll( '[name]' ).forEach( function ( el ) {
					el.name = el.name.replace( '__i__', String( next ) );
					el.disabled = false;
				} );
				row.querySelector( '.fw-mbr-pick__q' ).disabled = false;
				next++;
				list.insertBefore( row, tpl );
				sync( row );
				row.querySelector( '.fw-mbr-target__type' ).focus();
			} );

			list.addEventListener( 'change', function ( e ) {
				var row = e.target.closest( '.fw-mbr-target' );
				if ( ! row ) { return; }
				if ( e.target.matches( '.fw-mbr-target__type, .fw-mbr-target__pt, .fw-mbr-target__tax' ) ) {
					clearPick( row );
					sync( row );
				}
			} );
			list.addEventListener( 'click', function ( e ) {
				var rm = e.target.closest( '.fw-mbr-target__remove' );
				if ( rm ) { rm.closest( '.fw-mbr-target' ).remove(); return; }
				var opt = e.target.closest( '.fw-mbr-pick__results li' );
				if ( opt ) {
					var pick = opt.closest( '.fw-mbr-pick' );
					pick.querySelector( '.fw-mbr-pick__id' ).value = opt.dataset.id;
					pick.querySelector( '.fw-mbr-pick__q' ).value = opt.textContent;
					opt.parentNode.hidden = true;
				}
			} );

			var timer;
			list.addEventListener( 'input', function ( e ) {
				if ( ! e.target.matches( '.fw-mbr-pick__q' ) ) { return; }
				var row = e.target.closest( '.fw-mbr-target' );
				var type = row.dataset.type;
				var results = row.querySelector( '.fw-mbr-pick__results' );
				row.querySelector( '.fw-mbr-pick__id' ).value = '0';
				clearTimeout( timer );
				timer = setTimeout( function () {
					var url = form.dataset.find + '&q=' + encodeURIComponent( e.target.value ) +
						( 'term' === type ? '&kind=term&sub=' + row.querySelector( '.fw-mbr-target__tax' ).value
							: '&kind=post&sub=' + ( 'children' === type ? 'page' : row.querySelector( '.fw-mbr-target__pt' ).value ) );
					fetch( url, { credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } ).then( function ( r ) {
						results.innerHTML = '';
						( r.data || [] ).forEach( function ( item ) {
							var li = document.createElement( 'li' );
							li.setAttribute( 'role', 'option' );
							li.tabIndex = 0;
							li.dataset.id = item.id;
							li.textContent = item.label;
							results.appendChild( li );
						} );
						results.hidden = ! results.children.length;
					} );
				}, 250 );
			} );
			list.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key && e.target.matches( '.fw-mbr-pick__results li' ) ) { e.preventDefault(); e.target.click(); }
				if ( 'Enter' === e.key && e.target.matches( '.fw-mbr-pick__q' ) ) { e.preventDefault(); }
			} );
		}() );
		</script>
		<?php
	}
}
