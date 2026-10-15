<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Outgoing webhooks: tell another app when something happens in Membership.
 *
 * Events: every membership event as `membership.<event>` (joined, trial_started, renewed,
 * payment_failed, payment_recovered, plan_changed, paused, resumed, cancelled, reactivated,
 * expired, refunded, pending), `payment.paid`, `payment.refunded`, `course.enrolled`,
 * `course.completed`, and `ping` (the Send test button).
 *
 * Delivery is never inline with the change that caused it: events go into a queue table
 * and a cron worker posts them, retrying with backoff (1 min, 5 min, 30 min, 2 h, 12 h)
 * before giving up. After 20 failures in a row the webhook is switched off, so a dead
 * endpoint stops costing requests.
 *
 * Every request is signed, the same way as the other Unyson+ webhooks. The receiver
 * recomputes HMAC-SHA256( secret, "{timestamp}.{raw body}" ) and compares it with the
 * `v1` value of `X-Webhook-Signature: t=…,v1=…`, rejecting old timestamps.
 */
class FW_Membership_Webhooks {

	const VERSION      = '1.0.0';
	const CRON         = 'fw_mbr_webhooks_deliver';
	const BACKOFF      = array( 60, 300, 1800, 7200, 43200 );
	const MAX_FAILURES = 20;

	public static function init() {
		FW_Membership_Installer::module_tables( 'webhooks', self::VERSION, array( __CLASS__, 'schema' ), array( 'webhooks', 'webhook_queue' ) );
		add_action( 'unysonplus_membership_changed', array( __CLASS__, '_on_membership' ), 50, 3 );
		add_action( 'unysonplus_membership_payment_paid', array( __CLASS__, '_on_paid' ) );
		add_action( 'unysonplus_membership_payment_refunded', array( __CLASS__, '_on_refunded' ) );
		add_action( 'unysonplus_membership_course_enrolled', array( __CLASS__, '_on_enrolled' ), 50, 2 );
		add_action( 'unysonplus_membership_course_completed', array( __CLASS__, '_on_completed' ), 50, 2 );
		add_action( self::CRON, array( __CLASS__, 'deliver' ) );
		add_action( self::CRON . '_now', array( __CLASS__, 'deliver' ) );
		add_filter( 'unysonplus_membership_cron_hooks', function ( $hooks ) {
			$hooks[] = FW_Membership_Webhooks::CRON;
			return $hooks;
		} );
		add_filter( 'cron_schedules', function ( $s ) {
			$s['fw_mbr_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => __( 'Every five minutes', 'fw' ) );
			return $s;
		} );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'fw_mbr_five_minutes', self::CRON );
		}
	}

	public static function schema( $collate ) {
		$w = FW_Membership_Installer::table( 'webhooks' );
		$q = FW_Membership_Installer::table( 'webhook_queue' );
		return array(
			"CREATE TABLE {$w} (
	id bigint(20) unsigned NOT NULL auto_increment,
	url varchar(500) NOT NULL default '',
	secret varchar(64) NOT NULL default '',
	events text,
	active tinyint(1) unsigned NOT NULL default 1,
	failures int(11) unsigned NOT NULL default 0,
	last_status varchar(190) NOT NULL default '',
	last_sent_at datetime default NULL,
	created_at datetime default NULL,
	PRIMARY KEY  (id)
) {$collate};",
			"CREATE TABLE {$q} (
	id bigint(20) unsigned NOT NULL auto_increment,
	webhook_id bigint(20) unsigned NOT NULL default 0,
	event varchar(60) NOT NULL default '',
	payload longtext,
	attempts int(11) unsigned NOT NULL default 0,
	next_attempt_at datetime default NULL,
	created_at datetime default NULL,
	PRIMARY KEY  (id),
	KEY due (next_attempt_at)
) {$collate};",
		);
	}

	private static function t( $which = 'webhooks' ) {
		return FW_Membership_Installer::table( $which );
	}

	/** @return array event => description, every event a webhook can subscribe to. */
	public static function events() {
		$out = array();
		foreach ( FW_Membership_Events::catalogue() as $event => $about ) {
			$out[ 'membership.' . $event ] = $about;
		}
		$out['payment.paid']     = __( 'A payment (a first payment, a renewal or a one-off purchase) was received.', 'fw' );
		$out['payment.refunded'] = __( 'A payment was refunded.', 'fw' );
		$out['course.enrolled']  = __( 'A student started a course (Courses module).', 'fw' );
		$out['course.completed'] = __( 'A student completed every lesson of a course (Courses module).', 'fw' );
		/** Add events a custom module sends with FW_Membership_Webhooks::emit(). */
		return (array) apply_filters( 'unysonplus_membership_webhook_events', $out );
	}

	/* ---------------------------------------------------------------------- *
	 * Webhooks (the endpoints)
	 * ---------------------------------------------------------------------- */

	/** @return array[] */
	public static function all() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . self::t() . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'shape' ), $rows );
	}

	/** @return array|null */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? self::shape( $row ) : null;
	}

	private static function shape( array $row ) {
		$row['id']       = (int) $row['id'];
		$row['active']   = (bool) $row['active'];
		$row['failures'] = (int) $row['failures'];
		$row['events']   = array_values( array_filter( explode( ',', (string) $row['events'] ) ) );
		return $row;
	}

	/**
	 * @param string   $url    https address (http allowed only for local addresses in WP_DEBUG).
	 * @param string[] $events Event names; empty = every event.
	 *
	 * @return int|WP_Error The webhook id.
	 */
	public static function create( $url, array $events = array() ) {
		global $wpdb;
		$url = esc_url_raw( trim( (string) $url ), array( 'https', 'http' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'fw_mbr_webhook_url', __( 'Enter the full address that should receive the events, starting with https://', 'fw' ) );
		}
		$known  = array_keys( self::events() );
		$events = array_values( array_intersect( array_map( 'sanitize_text_field', $events ), $known ) );
		$wpdb->insert( self::t(), array(
			'url'        => $url,
			'secret'     => wp_generate_password( 40, false, false ),
			'events'     => implode( ',', $events ? $events : $known ),
			'active'     => 1,
			'created_at' => current_time( 'mysql', true ),
		) );
		return (int) $wpdb->insert_id;
	}

	/** @param string[] $events */
	public static function set_events( $id, array $events ) {
		global $wpdb;
		$known  = array_keys( self::events() );
		$events = array_values( array_intersect( $events, $known ) );
		$wpdb->update( self::t(), array( 'events' => implode( ',', $events ? $events : $known ) ), array( 'id' => (int) $id ) );
	}

	public static function set_active( $id, $active ) {
		global $wpdb;
		$wpdb->update( self::t(), array( 'active' => $active ? 1 : 0, 'failures' => 0 ), array( 'id' => (int) $id ) );
		if ( ! $active ) {
			$wpdb->delete( self::t( 'webhook_queue' ), array( 'webhook_id' => (int) $id ) ); // nothing piles up while it is off
		}
	}

	/** A new secret (the old one stops working at once). */
	public static function rotate_secret( $id ) {
		global $wpdb;
		$wpdb->update( self::t(), array( 'secret' => wp_generate_password( 40, false, false ) ), array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::t(), array( 'id' => (int) $id ) );
		$wpdb->delete( self::t( 'webhook_queue' ), array( 'webhook_id' => (int) $id ) );
	}

	/** @return int Deliveries waiting (all webhooks, or one). */
	public static function waiting( $id = 0 ) {
		global $wpdb;
		return (int) ( $id
			? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'webhook_queue' ) . ' WHERE webhook_id = %d', (int) $id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'webhook_queue' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ---------------------------------------------------------------------- *
	 * Events → queue
	 * ---------------------------------------------------------------------- */

	/**
	 * Queue an event for every active webhook that wants it. Custom modules call this too
	 * (add their event names with the unysonplus_membership_webhook_events filter).
	 *
	 * @param string $event
	 * @param array  $data
	 *
	 * @return int How many deliveries were queued.
	 */
	public static function emit( $event, array $data ) {
		global $wpdb;
		$hooks = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t() . ' WHERE active = 1 AND FIND_IN_SET(%s, events)', $event ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $hooks ) {
			return 0;
		}
		$payload = wp_json_encode( array(
			'id'         => wp_generate_uuid4(),
			'event'      => $event,
			'created_at' => gmdate( 'c' ),
			'site'       => home_url( '/' ),
			/** Change or add to the data a webhook sends for an event. */
			'data'       => apply_filters( 'unysonplus_membership_webhook_data', $data, $event ),
		) );
		foreach ( $hooks as $hid ) {
			$wpdb->insert( self::t( 'webhook_queue' ), array(
				'webhook_id'      => (int) $hid,
				'event'           => $event,
				'payload'         => $payload,
				'attempts'        => 0,
				'next_attempt_at' => current_time( 'mysql', true ),
				'created_at'      => current_time( 'mysql', true ),
			) );
		}
		// Deliver within moments rather than at the next scheduled run.
		if ( ! wp_next_scheduled( self::CRON . '_now' ) ) {
			wp_schedule_single_event( time(), self::CRON . '_now' );
		}
		return count( $hooks );
	}

	/** @return array The person, as webhooks send it. */
	private static function person( $user_id ) {
		$u = get_userdata( (int) $user_id );
		return array( 'id' => (int) $user_id, 'email' => $u ? $u->user_email : '', 'name' => $u ? $u->display_name : '', 'first_name' => $u ? $u->first_name : '', 'last_name' => $u ? $u->last_name : '' );
	}

	/** @return array|null */
	private static function plan( $plan_id ) {
		$p = FW_Membership_Plans::get( (int) $plan_id );
		return $p ? array( 'id' => (int) $p['id'], 'slug' => $p['slug'], 'name' => $p['name'] ) : null;
	}

	/** @internal */
	public static function _on_membership( $event, $m, $ctx ) {
		if ( ! is_array( $m ) || empty( $m['id'] ) ) {
			return;
		}
		$prev = ! empty( $ctx['previous_plan_id'] ) ? self::plan( $ctx['previous_plan_id'] ) : null;
		self::emit( 'membership.' . $event, array(
			'membership'    => FW_Membership_REST::membership_out( $m ),
			'member'        => self::person( $m['user_id'] ),
			'plan'          => self::plan( $m['plan_id'] ),
			'previous_plan' => $prev,
			'from'          => (string) ( $ctx['from'] ?? '' ),
			'to'            => (string) ( $ctx['to'] ?? '' ),
			'by'            => (int) ( $ctx['actor_id'] ?? 0 ),
			'note'          => (string) ( $ctx['note'] ?? '' ),
		) );
	}

	private static function payment_data( $p ) {
		$p = (array) $p;
		return array(
			'payment' => array(
				'id'             => (int) ( $p['id'] ?? 0 ),
				'type'           => (string) ( $p['type'] ?? '' ),
				'status'         => (string) ( $p['status'] ?? '' ),
				'amount'         => (float) ( $p['amount'] ?? 0 ),
				'tax'            => (float) ( $p['tax'] ?? 0 ),
				'currency'       => (string) ( $p['currency'] ?? '' ),
				'paid_by'        => (string) ( $p['gateway'] ?? '' ),
				'reference'      => (string) ( $p['gateway_ref'] ?? '' ),
				'invoice_number' => (string) ( $p['invoice_number'] ?? '' ),
				'coupon'         => (string) ( $p['coupon'] ?? '' ),
				'membership_id'  => (int) ( $p['membership_id'] ?? 0 ),
				'created_at'     => (string) ( $p['created_at'] ?? '' ),
			),
			'member'  => self::person( $p['user_id'] ?? 0 ),
			'plan'    => self::plan( $p['plan_id'] ?? 0 ),
		);
	}

	/** @internal */
	public static function _on_paid( $p ) {
		if ( $p ) {
			self::emit( 'payment.paid', self::payment_data( $p ) );
		}
	}

	/** @internal */
	public static function _on_refunded( $p ) {
		if ( $p ) {
			self::emit( 'payment.refunded', self::payment_data( $p ) );
		}
	}

	/** @internal */
	public static function _on_enrolled( $user_id, $course_id ) {
		self::emit( 'course.enrolled', array( 'member' => self::person( $user_id ), 'course' => array( 'id' => (int) $course_id, 'title' => get_the_title( $course_id ), 'url' => get_permalink( $course_id ) ) ) );
	}

	/** @internal */
	public static function _on_completed( $user_id, $course_id ) {
		$cert = class_exists( 'FW_Membership_Progress' ) ? FW_Membership_Progress::enrollment( $user_id, $course_id ) : null;
		self::emit( 'course.completed', array( 'member' => self::person( $user_id ), 'course' => array( 'id' => (int) $course_id, 'title' => get_the_title( $course_id ), 'url' => get_permalink( $course_id ) ), 'certificate' => $cert && '' !== (string) $cert['certificate'] && class_exists( 'FW_Membership_Certificates' ) ? FW_Membership_Certificates::url( $cert['certificate'] ) : '' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Delivery
	 * ---------------------------------------------------------------------- */

	/** @return string The X-Webhook-Signature value. */
	public static function signature( $secret, $body, $ts ) {
		return 't=' . $ts . ',v1=' . hash_hmac( 'sha256', $ts . '.' . $body, $secret );
	}

	/** Cron worker: send what is due, oldest first, within a time budget. */
	public static function deliver() {
		global $wpdb;
		$started = time();
		$due     = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'SELECT q.*, w.url, w.secret, w.active FROM ' . self::t( 'webhook_queue' ) . ' q JOIN ' . self::t() . ' w ON w.id = q.webhook_id
			 WHERE q.next_attempt_at <= %s ORDER BY q.id ASC LIMIT 50',
			current_time( 'mysql', true )
		) );
		foreach ( $due as $item ) {
			if ( time() - $started > 20 ) {
				break; // the rest waits for the next run
			}
			if ( ! (int) $item->active ) {
				$wpdb->delete( self::t( 'webhook_queue' ), array( 'id' => (int) $item->id ) );
				continue;
			}
			if ( self::send( $item ) ) {
				$wpdb->delete( self::t( 'webhook_queue' ), array( 'id' => (int) $item->id ) );
				continue;
			}
			$attempts = (int) $item->attempts + 1;
			if ( $attempts > count( self::BACKOFF ) ) {
				$wpdb->delete( self::t( 'webhook_queue' ), array( 'id' => (int) $item->id ) ); // gave up
			} else {
				$wpdb->update( self::t( 'webhook_queue' ), array(
					'attempts'        => $attempts,
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + self::BACKOFF[ $attempts - 1 ] ),
				), array( 'id' => (int) $item->id ) );
			}
		}
	}

	/**
	 * One POST; a 2xx answer is success. Records the outcome on the webhook.
	 *
	 * @param object $item queue row with url + secret
	 *
	 * @return bool
	 */
	public static function send( $item ) {
		global $wpdb;
		$ts   = time();
		$args = array(
			'timeout'     => 8,
			'redirection' => 0,
			'body'        => $item->payload,
			'headers'     => array(
				'Content-Type'        => 'application/json',
				'User-Agent'          => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
				'X-Webhook-Event'     => $item->event,
				'X-Webhook-Id'        => (string) ( json_decode( $item->payload )->id ?? '' ),
				'X-Webhook-Signature' => self::signature( $item->secret, $item->payload, $ts ),
			),
		);
		/**
		 * Local addresses are refused (wp_safe_remote_post) so a webhook cannot be aimed at the
		 * server's own network. Return true to allow them, e.g. for a receiver on a test site.
		 */
		$res  = apply_filters( 'unysonplus_membership_webhook_allow_local', false, $item->url ) ? wp_remote_post( $item->url, $args ) : wp_safe_remote_post( $item->url, $args );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$ok   = $code >= 200 && $code < 300;
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'UPDATE ' . self::t() . ' SET last_status = %s, last_sent_at = %s, failures = ' . ( $ok ? '0' : 'failures + 1' ) . ' WHERE id = %d',
			is_wp_error( $res ) ? substr( $res->get_error_message(), 0, 190 ) : (string) $code,
			current_time( 'mysql', true ),
			(int) $item->webhook_id
		) );
		if ( ! $ok && $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET active = 0 WHERE id = %d AND active = 1 AND failures >= %d', (int) $item->webhook_id, self::MAX_FAILURES ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->delete( self::t( 'webhook_queue' ), array( 'webhook_id' => (int) $item->webhook_id ) ); // switched off: drop what waits
		}
		return $ok;
	}

	/**
	 * Send a `ping` now (the Send test button), bypassing the queue.
	 *
	 * @return string What happened, for the admin notice.
	 */
	public static function ping( $id ) {
		$hook = self::get( $id );
		if ( ! $hook ) {
			return __( 'That webhook no longer exists.', 'fw' );
		}
		$payload = wp_json_encode( array(
			'id'         => wp_generate_uuid4(),
			'event'      => 'ping',
			'created_at' => gmdate( 'c' ),
			'site'       => home_url( '/' ),
			'data'       => array( 'message' => 'A test delivery from Membership.' ),
		) );
		$ok   = self::send( (object) array( 'webhook_id' => $hook['id'], 'url' => $hook['url'], 'secret' => $hook['secret'], 'event' => 'ping', 'payload' => $payload ) );
		$hook = self::get( $id );
		/* translators: %s: HTTP status or error */
		return $ok ? sprintf( __( 'Delivered (HTTP %s).', 'fw' ), $hook['last_status'] ) : sprintf( __( 'Not delivered: %s', 'fw' ), '' !== $hook['last_status'] && '0' !== $hook['last_status'] ? $hook['last_status'] : __( 'no answer', 'fw' ) );
	}
}
