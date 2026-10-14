<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Membership → Emails and Membership → Settings, and the account actions on a member's
 * page (approve, resend the confirmation, mark confirmed).
 */
class FW_Membership_Admin_Settings {

	/**
	 * @param string $action
	 *
	 * @return array|null Redirect query args, or null when not ours.
	 */
	public static function handle( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification -- verified by the admin page before dispatch.
		$r = wp_unslash( $_REQUEST );
		switch ( $action ) {
			case 'save_emails':
				FW_Membership_Emails::save( (array) ( $r['emails'] ?? array() ) );
				FW_Membership_Admin_Page::flash( 'success', __( 'Emails saved.', 'fw' ) );
				return array( 'tab' => 'emails' );

			case 'test_email':
				$key = sanitize_key( (string) ( $r['email'] ?? '' ) );
				$me  = wp_get_current_user();
				$ok  = FW_Membership_Emails::send( $key, $me->ID, array(
					'plan'       => __( 'Gold', 'fw' ),
					'ends_at'    => date_i18n( get_option( 'date_format' ), strtotime( '+1 year' ) ),
					'verify_url' => home_url( '/?fw_mbr_verify=example' ),
					'verify_days' => (int) FW_Membership_Settings::get( 'verify_days' ),
				), true, $me->user_email );
				FW_Membership_Admin_Page::flash( $ok ? 'success' : 'error', $ok ? sprintf( __( 'A test was sent to %s.', 'fw' ), $me->user_email ) : __( 'The test could not be sent. Check the site\'s mail settings (Mailer).', 'fw' ) );
				return array( 'tab' => 'emails' );

			case 'save_profile_fields':
				$res = FW_Membership_Profile::save_fields( (array) ( $r['fields'] ?? array() ) );
				FW_Membership_Admin_Page::flash_result( $res, __( 'Profile fields saved.', 'fw' ) );
				return array( 'tab' => 'fields' );

			case 'save_member_profile':
				$uid = (int) ( $r['user_id'] ?? 0 );
				$res = get_userdata( $uid ) ? FW_Membership_Profile::save_values( $uid, FW_Membership_Profile::fields(), (array) ( $r['fw_mbr_profile'] ?? array() ), true ) : new WP_Error( 'x', __( 'Member not found.', 'fw' ) );
				FW_Membership_Admin_Page::flash_result( $res, __( 'Profile saved.', 'fw' ) );
				return array( 'tab' => 'members', 'member' => $uid );

			case 'save_settings':
				FW_Membership_Settings::save( (array) $r );
				/** A module saves its own panel of Membership → Settings. */
				do_action( 'unysonplus_membership_save_settings', (array) $r );
				FW_Membership_Admin_Page::flash( 'success', __( 'Settings saved.', 'fw' ) );
				return array( 'tab' => 'settings' );

			case 'remove_data':
				if ( 'DELETE' !== trim( (string) ( $r['confirm'] ?? '' ) ) ) {
					FW_Membership_Admin_Page::flash( 'error', __( 'Type DELETE to confirm. Nothing was removed.', 'fw' ) );
					return array( 'tab' => 'settings' );
				}
				FW_Membership_Installer::uninstall();
				delete_option( FW_Membership_Settings::OPTION );
				delete_option( FW_Membership_Emails::OPTION );
				FW_Membership_Installer::install(); // fresh, empty tables so the screens keep working
				FW_Membership_Admin_Page::flash( 'success', __( 'All Membership data was removed: plans, members, rules, payments and settings.', 'fw' ) );
				return array( 'tab' => 'home' );

			case 'approve_member':
				$uid = (int) ( $r['user_id'] ?? 0 );
				FW_Membership_Verification::approve( $uid );
				FW_Membership_Admin_Page::flash( 'success', __( 'Approved. Their membership has started and they have been emailed.', 'fw' ) );
				return array( 'tab' => 'members', 'member' => $uid );

			case 'resend_verify':
				$uid = (int) ( $r['user_id'] ?? 0 );
				$ok  = 'verify' === FW_Membership_Verification::state( $uid ) && FW_Membership_Verification::send( $uid );
				FW_Membership_Admin_Page::flash( $ok ? 'success' : 'error', $ok ? __( 'A new confirmation link was sent. The old one no longer works.', 'fw' ) : __( 'The email could not be sent.', 'fw' ) );
				return array( 'tab' => 'members', 'member' => $uid );

			case 'mark_verified':
				$uid   = (int) ( $r['user_id'] ?? 0 );
				$state = FW_Membership_Verification::mark_verified( $uid );
				FW_Membership_Admin_Page::flash( 'success', 'approval' === $state ? __( 'Marked as confirmed. They now wait for approval.', 'fw' ) : __( 'Marked as confirmed. Their membership has started.', 'fw' ) );
				return array( 'tab' => 'members', 'member' => $uid );
		}
		// phpcs:enable
		return null;
	}

	/* ---------------------------------------------------------------------- */

	public static function render_emails() {
		$emails = FW_Membership_Emails::all();
		?>
		<p class="fw-mbr-status-line"><?php esc_html_e( 'The emails members receive. Write them as plain text; links become clickable. They are sent through your site\'s mail settings.', 'fw' ); ?></p>
		<form method="post" class="fw-mbr-emails">
			<?php FW_Membership_Admin_Page::form_fields( 'save_emails' ); ?>
			<?php foreach ( $emails as $key => $e ) : ?>
				<?php $id = 'fw-mbr-email-' . $key; ?>
				<details class="fw-mbr-panel fw-mbr-email"<?php echo 'welcome' === $key ? ' open' : ''; ?>>
					<summary>
						<span class="fw-mbr-email__title"><?php echo esc_html( $e['label'] ); ?></span>
						<span class="fw-mbr-muted"><?php echo 'admin' === $e['to'] ? esc_html__( 'to you', 'fw' ) : esc_html__( 'to the member', 'fw' ); ?></span>
						<?php if ( ! $e['enabled'] ) : ?><span class="fw-mbr-mstatus fw-mbr-mstatus--expired"><?php esc_html_e( 'Off', 'fw' ); ?></span><?php endif; ?>
					</summary>
					<p class="fw-mbr-muted"><?php echo esc_html( $e['when'] ); ?></p>
					<?php if ( 'verify' !== $key ) : ?>
						<label class="fw-mbr-check-line"><input type="checkbox" name="emails[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $e['enabled'] ); ?> /> <?php esc_html_e( 'Send this email', 'fw' ); ?></label>
					<?php else : ?>
						<input type="hidden" name="emails[verify][enabled]" value="1" />
					<?php endif; ?>
					<p class="fw-mbr-field">
						<label for="<?php echo esc_attr( $id ); ?>-s"><?php esc_html_e( 'Subject', 'fw' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $id ); ?>-s" name="emails[<?php echo esc_attr( $key ); ?>][subject]" value="<?php echo esc_attr( $e['subject'] ); ?>" />
					</p>
					<p class="fw-mbr-field">
						<label for="<?php echo esc_attr( $id ); ?>-b"><?php esc_html_e( 'Message', 'fw' ); ?></label>
						<textarea id="<?php echo esc_attr( $id ); ?>-b" name="emails[<?php echo esc_attr( $key ); ?>][body]" rows="8"><?php echo esc_textarea( $e['body'] ); ?></textarea>
					</p>
					<p><a class="button button-small" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'test_email', array( 'email' => $key ), 'emails' ) ); ?>"><?php esc_html_e( 'Send me a test', 'fw' ); ?></a>
						<span class="fw-mbr-muted"><?php esc_html_e( 'Save first to test your changes.', 'fw' ); ?></span></p>
				</details>
			<?php endforeach; ?>
			<section class="fw-mbr-panel">
				<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'Placeholders', 'fw' ); ?></h2></header>
				<ul class="fw-mbr-placeholders">
					<?php foreach ( FW_Membership_Emails::placeholders() as $ph => $desc ) : ?>
						<li><code><?php echo esc_html( $ph ); ?></code> <?php echo esc_html( $desc ); ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save emails', 'fw' ); ?></button></p>
		</form>
		<?php
	}

	public static function render_profile_fields() {
		$fields   = array_values( FW_Membership_Profile::fields() );
		$plans    = FW_Membership_Plans::all();
		$types    = array(
			'text'     => __( 'Text', 'fw' ),
			'textarea' => __( 'Longer text', 'fw' ),
			'select'   => __( 'Choice (list)', 'fw' ),
			'checkbox' => __( 'Tick box', 'fw' ),
			'date'     => __( 'Date', 'fw' ),
			'number'   => __( 'Number', 'fw' ),
			'tel'      => __( 'Phone', 'fw' ),
			'url'      => __( 'Web address', 'fw' ),
		);
		$fields[] = null; // template row
		?>
		<p class="fw-mbr-status-line"><?php esc_html_e( 'Extra details to keep about members. Members fill them in on their account page; a sign-up form fills one when it has a field with the same label.', 'fw' ); ?></p>
		<form method="post" class="fw-mbr-panel fw-mbr-pfields-form">
			<?php FW_Membership_Admin_Page::form_fields( 'save_profile_fields' ); ?>
			<ul class="fw-mbr-pfields" id="fw-mbr-pfields">
				<?php foreach ( $fields as $i => $f ) : ?>
					<?php
					$tpl = null === $f;
					$f   = $f ? $f : array( 'key' => '', 'label' => '', 'type' => 'text', 'choices' => array(), 'required' => false, 'plans' => array(), 'member_edit' => true, 'directory' => 'hidden' );
					$n   = 'fields[' . ( $tpl ? '__i__' : $i ) . ']';
					?>
					<li class="fw-mbr-pfield<?php echo $tpl ? ' fw-mbr-pfield--tpl' : ''; ?>"<?php echo $tpl ? ' hidden' : ''; ?>>
						<div class="fw-mbr-fields">
							<p class="fw-mbr-field">
								<label><?php esc_html_e( 'Label', 'fw' ); ?><input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $f['label'] ); ?>" placeholder="<?php esc_attr_e( 'Company', 'fw' ); ?>"<?php disabled( $tpl ); ?> /></label>
							</p>
							<p class="fw-mbr-field">
								<label><?php esc_html_e( 'Type', 'fw' ); ?>
									<select name="<?php echo esc_attr( $n ); ?>[type]"<?php disabled( $tpl ); ?>>
										<?php foreach ( $types as $k => $label ) : ?>
											<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['type'], $k ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							</p>
							<p class="fw-mbr-field">
								<label><?php esc_html_e( 'Key (advanced)', 'fw' ); ?><input type="text" class="code" name="<?php echo esc_attr( $n ); ?>[key]" value="<?php echo esc_attr( $f['key'] ); ?>" placeholder="<?php esc_attr_e( 'made from the label', 'fw' ); ?>"<?php disabled( $tpl ); ?> /></label>
							</p>
							<p class="fw-mbr-field">
								<label><?php esc_html_e( 'Options (for a choice, one per line)', 'fw' ); ?><textarea rows="2" name="<?php echo esc_attr( $n ); ?>[choices]"<?php disabled( $tpl ); ?>><?php echo esc_textarea( implode( "\n", $f['choices'] ) ); ?></textarea></label>
							</p>
						</div>
						<div class="fw-mbr-pfield__opts">
							<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[required]" value="1" <?php checked( $f['required'] ); ?><?php disabled( $tpl ); ?> /> <?php esc_html_e( 'Required', 'fw' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[member_edit]" value="1" <?php checked( $f['member_edit'] ); ?><?php disabled( $tpl ); ?> /> <?php esc_html_e( 'Members can edit it', 'fw' ); ?></label>
							<?php if ( class_exists( 'FW_Membership_Directory' ) ) : ?>
								<label><?php esc_html_e( 'In the directory:', 'fw' ); ?>
									<select name="<?php echo esc_attr( $n ); ?>[directory]"<?php disabled( $tpl ); ?>>
										<option value="hidden" <?php selected( $f['directory'] ?? 'hidden', 'hidden' ); ?>><?php esc_html_e( 'Not shown', 'fw' ); ?></option>
										<option value="members" <?php selected( $f['directory'] ?? 'hidden', 'members' ); ?>><?php esc_html_e( 'Members', 'fw' ); ?></option>
										<option value="public" <?php selected( $f['directory'] ?? 'hidden', 'public' ); ?>><?php esc_html_e( 'Everyone', 'fw' ); ?></option>
									</select>
								</label>
							<?php endif; ?>
							<?php if ( $plans ) : ?>
								<span class="fw-mbr-muted"><?php esc_html_e( 'Only for:', 'fw' ); ?></span>
								<?php foreach ( $plans as $p ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[plans][]" value="<?php echo (int) $p['id']; ?>" <?php checked( in_array( $p['id'], $f['plans'], true ) ); ?><?php disabled( $tpl ); ?> /> <?php echo esc_html( $p['name'] ); ?></label>
								<?php endforeach; ?>
								<span class="fw-mbr-muted"><?php esc_html_e( '(none ticked: every member)', 'fw' ); ?></span>
							<?php endif; ?>
							<button type="button" class="button-link fw-mbr-pfield__remove"><?php esc_html_e( 'Remove', 'fw' ); ?></button>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="fw-mbr-actions">
				<button type="button" class="button" id="fw-mbr-pfield-add"><?php esc_html_e( 'Add a field', 'fw' ); ?></button>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save profile fields', 'fw' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Removing a field keeps the values already stored; adding it back with the same key shows them again.', 'fw' ); ?></p>
		</form>
		<script>
		( function () {
			var list = document.getElementById( 'fw-mbr-pfields' );
			var tpl = list.querySelector( '.fw-mbr-pfield--tpl' );
			var next = list.children.length;
			document.getElementById( 'fw-mbr-pfield-add' ).addEventListener( 'click', function () {
				var row = tpl.cloneNode( true );
				row.hidden = false;
				row.classList.remove( 'fw-mbr-pfield--tpl' );
				row.querySelectorAll( '[name]' ).forEach( function ( el ) { el.name = el.name.replace( '__i__', String( next ) ); el.disabled = false; } );
				next++;
				list.insertBefore( row, tpl );
				row.querySelector( 'input[type=text]' ).focus();
			} );
			list.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '.fw-mbr-pfield__remove' ) ) { e.target.closest( '.fw-mbr-pfield' ).remove(); }
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * The member's profile fields on their page in the app.
	 *
	 * @param int $user_id
	 */
	public static function render_member_profile( $user_id ) {
		$fields = FW_Membership_Profile::fields();
		if ( ! $fields ) {
			return;
		}
		?>
		<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-prof">
			<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-prof"><?php esc_html_e( 'Profile', 'fw' ); ?></h2></header>
			<form method="post" class="fw-mbr-fields fw-mbr-fields--stack fw-mbr-member-profile">
				<?php FW_Membership_Admin_Page::form_fields( 'save_member_profile' ); ?>
				<input type="hidden" name="user_id" value="<?php echo (int) $user_id; ?>" />
				<?php echo FW_Membership_Profile::inputs( $user_id, $fields, 'fw-mbr-field' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Save profile', 'fw' ); ?></button></p>
			</form>
		</section>
		<?php
	}

	public static function render_settings() {
		$s     = FW_Membership_Settings::get();
		$pages = get_pages( array( 'post_status' => array( 'publish', 'private', 'draft' ) ) );
		$page_select = function ( $name, $value, $empty ) use ( $pages ) {
			printf( '<select id="fw-mbr-%1$s" name="%1$s"><option value="0">%2$s</option>', esc_attr( $name ), esc_html( $empty ) );
			foreach ( $pages as $p ) {
				printf( '<option value="%d" %s>%s</option>', (int) $p->ID, selected( (int) $value, (int) $p->ID, false ), esc_html( '' !== $p->post_title ? $p->post_title : '#' . $p->ID ) );
			}
			echo '</select>';
		};
		?>
		<form method="post" class="fw-mbr-record">
			<?php FW_Membership_Admin_Page::form_fields( 'save_settings' ); ?>
			<div class="fw-mbr-record__main">
				<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-pages">
					<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-pages"><?php esc_html_e( 'Member pages', 'fw' ); ?></h2></header>
					<div class="fw-mbr-fields fw-mbr-fields--stack">
						<p class="fw-mbr-field">
							<label for="fw-mbr-account_page"><?php esc_html_e( 'Account page', 'fw' ); ?></label>
							<?php $page_select( 'account_page', $s['account_page'], __( 'None (members use their WordPress profile)', 'fw' ) ); ?>
							<span class="description"><?php esc_html_e( 'The page with the Member Account element. Members land here after logging in (unless their plan says otherwise), and emails link to it.', 'fw' ); ?></span>
						</p>
						<p class="fw-mbr-field">
							<label for="fw-mbr-login_page"><?php esc_html_e( 'Login page', 'fw' ); ?></label>
							<?php $page_select( 'login_page', $s['login_page'], __( 'None (the WordPress login screen)', 'fw' ) ); ?>
							<span class="description"><?php esc_html_e( 'The page with the Member Login element, used by links in emails and prompts.', 'fw' ); ?></span>
						</p>
					</div>
				</section>
				<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-signup">
					<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-signup"><?php esc_html_e( 'Sign-ups', 'fw' ); ?></h2></header>
					<p class="fw-mbr-muted"><?php esc_html_e( 'Sign-up forms are ordinary contact forms with the "Register a member" action switched on in their Settings → Actions. Each form chooses its plan, email confirmation and approval.', 'fw' ); ?></p>
					<div class="fw-mbr-fields fw-mbr-fields--stack">
						<p class="fw-mbr-field">
							<label for="fw-mbr-after_verify"><?php esc_html_e( 'After confirming their email, send them to', 'fw' ); ?></label>
							<input type="url" id="fw-mbr-after_verify" name="after_verify" value="<?php echo esc_attr( $s['after_verify'] ); ?>" placeholder="<?php echo esc_attr( FW_Membership_Settings::account_url() ); ?>" />
						</p>
						<p class="fw-mbr-field">
							<label for="fw-mbr-verify_days"><?php esc_html_e( 'Confirmation links work for (days)', 'fw' ); ?></label>
							<input type="number" id="fw-mbr-verify_days" name="verify_days" min="1" max="30" value="<?php echo (int) $s['verify_days']; ?>" />
						</p>
						<label class="fw-mbr-check-line"><input type="checkbox" name="notify_admin" value="1" <?php checked( $s['notify_admin'] ); ?> /> <?php esc_html_e( 'Email me about each sign-up that waits for approval', 'fw' ); ?></label>
						<p class="fw-mbr-field">
							<label for="fw-mbr-admin_email"><?php esc_html_e( 'Send those emails to', 'fw' ); ?></label>
							<input type="email" id="fw-mbr-admin_email" name="admin_email" value="<?php echo esc_attr( $s['admin_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
						</p>
					</div>
				</section>
				<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-members">
					<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-members"><?php esc_html_e( 'Members', 'fw' ); ?></h2></header>
					<label class="fw-mbr-check-line"><input type="checkbox" name="member_cancel" value="1" <?php checked( $s['member_cancel'] ); ?> /> <?php esc_html_e( 'Members may cancel a membership from their account page (access continues to its end date)', 'fw' ); ?></label>
				</section>
				<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-look">
					<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-look"><?php esc_html_e( 'Look', 'fw' ); ?></h2></header>
					<p class="fw-mbr-muted"><?php esc_html_e( 'Membership buttons (prompts, login, account) use your theme’s button classes, the same ones the Button element and forms use, so they follow your Theme Settings button presets.', 'fw' ); ?></p>
					<div class="fw-mbr-fields">
						<p class="fw-mbr-field">
							<label for="fw-mbr-button_class"><?php esc_html_e( 'Main button classes', 'fw' ); ?></label>
							<input type="text" id="fw-mbr-button_class" name="button_class" class="code" value="<?php echo esc_attr( $s['button_class'] ); ?>" />
						</p>
						<p class="fw-mbr-field">
							<label for="fw-mbr-button_class_2"><?php esc_html_e( 'Second button classes', 'fw' ); ?></label>
							<input type="text" id="fw-mbr-button_class_2" name="button_class_2" class="code" value="<?php echo esc_attr( $s['button_class_2'] ); ?>" />
						</p>
					</div>
				</section>
				<?php
				/** More panels in Membership → Settings (saved on unysonplus_membership_save_settings). */
				do_action( 'unysonplus_membership_settings_panels' );
				?>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'fw' ); ?></button></p>
			</div>
		</form>

		<form method="post" class="fw-mbr-panel fw-mbr-danger-zone" style="margin-top:16px" onsubmit="return confirm('<?php echo esc_js( __( 'Remove ALL Membership data? This cannot be undone.', 'fw' ) ); ?>');">
			<?php FW_Membership_Admin_Page::form_fields( 'remove_data' ); ?>
			<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'Remove all data', 'fw' ); ?></h2></header>
			<p class="fw-mbr-muted"><?php esc_html_e( 'Deletes every plan, membership, history entry, access rule and payment record, and these settings. WordPress accounts are kept. Switching the extension off never removes anything; only this does.', 'fw' ); ?></p>
			<p class="fw-mbr-inline">
				<label for="fw-mbr-confirm-delete"><?php esc_html_e( 'Type DELETE to confirm', 'fw' ); ?></label>
				<input type="text" id="fw-mbr-confirm-delete" name="confirm" autocomplete="off" />
				<button type="submit" class="button fw-mbr-danger"><?php esc_html_e( 'Remove all Membership data', 'fw' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * The account state on a member's page: waiting for confirmation or approval.
	 *
	 * @param int $user_id
	 */
	public static function render_member_state( $user_id ) {
		$state = FW_Membership_Verification::state( $user_id );
		if ( '' === $state ) {
			return;
		}
		$args = array( 'user_id' => (int) $user_id );
		?>
		<section class="fw-mbr-panel fw-mbr-held" aria-labelledby="fw-mbr-h-held">
			<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-held"><?php echo 'verify' === $state ? esc_html__( 'Waiting for email confirmation', 'fw' ) : esc_html__( 'Waiting for your approval', 'fw' ); ?></h2></header>
			<p><?php echo 'verify' === $state ? esc_html__( 'They signed up but have not clicked the link in the confirmation email yet. They cannot log in and their membership has not started.', 'fw' ) : esc_html__( 'They signed up and their membership starts as soon as you approve them. They cannot log in until then.', 'fw' ); ?></p>
			<p class="fw-mbr-actions">
				<?php if ( 'approval' === $state ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'approve_member', $args, 'members' ) ); ?>"><?php esc_html_e( 'Approve', 'fw' ); ?></a>
				<?php else : ?>
					<a class="button" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'resend_verify', $args, 'members' ) ); ?>"><?php esc_html_e( 'Resend the confirmation email', 'fw' ); ?></a>
					<a class="button" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'mark_verified', $args, 'members' ) ); ?>"><?php esc_html_e( 'Mark as confirmed', 'fw' ); ?></a>
				<?php endif; ?>
			</p>
		</section>
		<?php
	}
}
