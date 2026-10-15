<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Membership → Webhooks: add the addresses that receive events, choose the events, send a
 * test, see the last answer, switch off, change the secret, delete.
 */
class FW_Membership_Admin_Webhooks {

	public static function init() {
		add_action( 'unysonplus_membership_render_view', array( __CLASS__, '_render' ) );
		add_filter( 'unysonplus_membership_admin_action', array( __CLASS__, '_handle' ), 10, 2 );
	}

	/** @internal */
	public static function _handle( $back, $action ) {
		// phpcs:disable WordPress.Security.NonceVerification -- verified by the admin page before dispatch.
		$r    = wp_unslash( $_REQUEST );
		$id   = (int) ( $r['id'] ?? 0 );
		$here = array( 'tab' => 'webhooks' );
		switch ( $action ) {
			case 'webhook_add':
				$res = FW_Membership_Webhooks::create( (string) ( $r['url'] ?? '' ), array_map( 'sanitize_text_field', (array) ( $r['events'] ?? array() ) ) );
				FW_Membership_Admin_Page::flash_result( $res, __( 'Webhook added. Copy its secret into the receiving app so it can check each request, then send a test.', 'fw' ) );
				return $here;
			case 'webhook_events':
				FW_Membership_Webhooks::set_events( $id, array_map( 'sanitize_text_field', (array) ( $r['events'] ?? array() ) ) );
				FW_Membership_Admin_Page::flash( 'success', __( 'Events saved.', 'fw' ) );
				return $here;
			case 'webhook_test':
				FW_Membership_Admin_Page::flash( 'info', FW_Membership_Webhooks::ping( $id ) );
				return $here;
			case 'webhook_on':
			case 'webhook_off':
				FW_Membership_Webhooks::set_active( $id, 'webhook_on' === $action );
				FW_Membership_Admin_Page::flash( 'success', 'webhook_on' === $action ? __( 'Webhook switched on.', 'fw' ) : __( 'Webhook switched off. Nothing is sent to it until you switch it on.', 'fw' ) );
				return $here;
			case 'webhook_secret':
				FW_Membership_Webhooks::rotate_secret( $id );
				FW_Membership_Admin_Page::flash( 'success', __( 'New secret made. Put it in the receiving app: requests signed with the old one now fail its check.', 'fw' ) );
				return $here;
			case 'webhook_delete':
				FW_Membership_Webhooks::delete( $id );
				FW_Membership_Admin_Page::flash( 'success', __( 'Webhook deleted.', 'fw' ) );
				return $here;
		}
		// phpcs:enable
		return $back;
	}

	private static function event_boxes( array $checked ) {
		$all = FW_Membership_Webhooks::events();
		?>
		<fieldset class="fw-mbr-field fw-mbr-field--wide">
			<legend><?php esc_html_e( 'Events to send', 'fw' ); ?></legend>
			<div class="fw-mbr-webhook-events">
				<?php foreach ( $all as $event => $about ) : ?>
					<label class="fw-mbr-check" title="<?php echo esc_attr( $about ); ?>"><input type="checkbox" name="events[]" value="<?php echo esc_attr( $event ); ?>" <?php checked( ! $checked || in_array( $event, $checked, true ) ); ?> /> <code><?php echo esc_html( $event ); ?></code></label>
				<?php endforeach; ?>
			</div>
			<span class="description"><?php esc_html_e( 'Hover an event to see when it is sent. Nothing ticked: every event.', 'fw' ); ?></span>
		</fieldset>
		<?php
	}

	/** @internal */
	public static function _render( $tab ) {
		if ( 'webhooks' !== $tab ) {
			return;
		}
		$hooks = FW_Membership_Webhooks::all();
		?>
		<p class="fw-mbr-muted"><?php esc_html_e( 'Tell another app the moment something happens: someone joins, renews, cancels, pays or finishes a course. Each event is posted as JSON to the address you add, signed with its secret, in the background, with retries if the app is down.', 'fw' ); ?> <a href="https://docs.unysonplus.com/membership/running-your-site/developers" target="_blank" rel="noopener"><?php esc_html_e( 'How to receive them', 'fw' ); ?></a></p>
		<form method="post" class="fw-mbr-panel">
			<?php FW_Membership_Admin_Page::form_fields( 'webhook_add' ); ?>
			<header class="fw-mbr-panel__head"><h2><?php esc_html_e( 'Add a webhook', 'fw' ); ?></h2></header>
			<div class="fw-mbr-fields">
				<p class="fw-mbr-field fw-mbr-field--wide"><label for="fw-mbr-whurl"><?php esc_html_e( 'Address that receives the events', 'fw' ); ?></label><input type="url" id="fw-mbr-whurl" name="url" required placeholder="https://example.com/hooks/membership" /><span class="description"><?php esc_html_e( 'From the other app (an automation service, your CRM, your own server). It must answer with a 2xx status.', 'fw' ); ?></span></p>
				<?php self::event_boxes( array() ); ?>
			</div>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add webhook', 'fw' ); ?></button></p>
		</form>
		<?php if ( ! $hooks ) : ?>
			<?php FW_Membership_App::empty_state( 'dashicons-rest-api', __( 'No webhooks yet', 'fw' ), __( 'Add the address of the app that should hear about members joining, paying and leaving.', 'fw' ) ); ?>
			<?php
			return;
		endif;
		foreach ( $hooks as $h ) :
			$waiting = FW_Membership_Webhooks::waiting( $h['id'] );
			?>
			<section class="fw-mbr-panel">
				<header class="fw-mbr-panel__head">
					<h2><code><?php echo esc_html( $h['url'] ); ?></code></h2>
					<?php echo FW_Membership_App::status_pill( $h['active'] ? 'active' : 'expired' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</header>
				<dl class="fw-mbr-dl">
					<div><dt><?php esc_html_e( 'Last answer', 'fw' ); ?></dt><dd><?php echo '' !== $h['last_status'] ? esc_html( $h['last_status'] . ( $h['last_sent_at'] ? ' · ' . sprintf( __( '%s ago', 'fw' ), human_time_diff( strtotime( $h['last_sent_at'] . ' UTC' ) ) ) : '' ) ) : esc_html__( 'Nothing sent yet', 'fw' ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Waiting to send', 'fw' ); ?></dt><dd><?php echo (int) $waiting; ?></dd></div>
					<div><dt><?php esc_html_e( 'Failures in a row', 'fw' ); ?></dt><dd><?php echo (int) $h['failures']; ?><?php echo ! $h['active'] && $h['failures'] >= FW_Membership_Webhooks::MAX_FAILURES ? ' · ' . esc_html__( 'switched off after too many failures', 'fw' ) : ''; ?></dd></div>
					<div><dt><?php esc_html_e( 'Secret', 'fw' ); ?></dt><dd><details><summary><?php esc_html_e( 'Show', 'fw' ); ?></summary><code class="fw-mbr-secret"><?php echo esc_html( $h['secret'] ); ?></code></details></dd></div>
				</dl>
				<form method="post">
					<?php FW_Membership_Admin_Page::form_fields( 'webhook_events' ); ?>
					<input type="hidden" name="id" value="<?php echo (int) $h['id']; ?>" />
					<?php self::event_boxes( $h['events'] ); ?>
					<p class="fw-mbr-inline">
						<button type="submit" class="button"><?php esc_html_e( 'Save events', 'fw' ); ?></button>
						<a class="button" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'webhook_test', array( 'id' => $h['id'] ), 'webhooks' ) ); ?>"><?php esc_html_e( 'Send a test', 'fw' ); ?></a>
						<a class="button" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( $h['active'] ? 'webhook_off' : 'webhook_on', array( 'id' => $h['id'] ), 'webhooks' ) ); ?>"><?php echo esc_html( $h['active'] ? __( 'Switch off', 'fw' ) : __( 'Switch on', 'fw' ) ); ?></a>
						<a class="button" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'webhook_secret', array( 'id' => $h['id'] ), 'webhooks' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Make a new secret? The receiving app must be given the new one.', 'fw' ) ); ?>');"><?php esc_html_e( 'New secret', 'fw' ); ?></a>
						<a class="button fw-mbr-danger" href="<?php echo esc_url( FW_Membership_Admin_Page::action_url( 'webhook_delete', array( 'id' => $h['id'] ), 'webhooks' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this webhook? Deliveries still waiting are dropped.', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete', 'fw' ); ?></a>
					</p>
				</form>
			</section>
			<?php
		endforeach;
	}
}
