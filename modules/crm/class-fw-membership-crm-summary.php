<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Member value on the contact: managed custom fields (read-only in the CRM, written by
 * Membership) that segments, filters and the CRM's "date reached" automation trigger
 * use, and a Members card on the CRM's Home.
 *
 *   membership_status       Member | Former member | Waiting      (choice)
 *   membership_plans        the plans held now                   (text)
 *   membership_since        first membership start               (date)
 *   membership_renews_at    next renewal                         (date)
 *   membership_ends_at      when access ends (no renewal)         (date)
 *   membership_total_paid   paid minus refunds, site currency    (money)
 */
class FW_Membership_CRM_Summary {

	public static function init() {
		add_filter( 'unysonplus_crm_dashboard_cards', array( __CLASS__, '_card' ) );
		add_action( 'unysonplus_membership_payment_paid', array( __CLASS__, '_payment' ), 40 );
		add_action( 'unysonplus_membership_payment_refunded', array( __CLASS__, '_payment' ), 40 );
		if ( FW_Membership_CRM::settings()['fields'] && ! get_option( 'fw_ext_membership_crm_fields_v1' ) ) {
			add_action( 'init', array( __CLASS__, 'define_fields' ), 20 );
		}
	}

	/** Register the managed fields (once; safe to repeat). */
	public static function define_fields() {
		if ( ! class_exists( 'FW_CRM_Fields' ) ) {
			return;
		}
		$group = __( 'Membership', 'fw' );
		$defs  = array(
			array( 'key' => 'membership_status', 'label' => __( 'Membership status', 'fw' ), 'type' => 'choice', 'choices' => array( __( 'Member', 'fw' ), __( 'Former member', 'fw' ), __( 'Waiting', 'fw' ) ) ),
			array( 'key' => 'membership_plans', 'label' => __( 'Membership plans', 'fw' ), 'type' => 'text' ),
			array( 'key' => 'membership_since', 'label' => __( 'Member since', 'fw' ), 'type' => 'date' ),
			array( 'key' => 'membership_renews_at', 'label' => __( 'Membership renews on', 'fw' ), 'type' => 'date' ),
			array( 'key' => 'membership_ends_at', 'label' => __( 'Membership ends on', 'fw' ), 'type' => 'date' ),
			array( 'key' => 'membership_total_paid', 'label' => __( 'Total paid for membership', 'fw' ), 'type' => 'money' ),
		);
		foreach ( $defs as $d ) {
			FW_CRM_Fields::save_definition( array_merge( $d, array( 'object' => 'contact', 'group' => $group, 'managed_by' => FW_Membership_CRM::SOURCE ) ) );
		}
		update_option( 'fw_ext_membership_crm_fields_v1', 1, false );
	}

	/**
	 * Paid minus refunded, per currency, as money strings.
	 *
	 * @param int $user_id
	 *
	 * @return string[]
	 */
	public static function paid_total( $user_id ) {
		$sum = self::paid_sums( $user_id );
		$out = array();
		foreach ( $sum as $cur => $n ) {
			$out[] = FW_Membership_Plans::money( $n, $cur );
		}
		return $out;
	}

	/** @return array currency => amount */
	private static function paid_sums( $user_id ) {
		if ( ! class_exists( 'FW_Membership_Payments' ) ) {
			return array();
		}
		$sum = array();
		foreach ( FW_Membership_Payments::query( array( 'user_id' => (int) $user_id, 'status' => 'paid', 'per_page' => 500 ) ) as $p ) {
			$sum[ $p['currency'] ] = ( $sum[ $p['currency'] ] ?? 0 ) + (float) $p['amount'];
		}
		return array_filter( $sum );
	}

	/**
	 * Write the fields for one contact.
	 *
	 * @param int     $contact_id
	 * @param int     $user_id
	 * @param array[] $memberships All of the user's memberships.
	 */
	public static function update_contact( $contact_id, $user_id, array $memberships ) {
		if ( ! class_exists( 'FW_CRM_Fields' ) ) {
			return;
		}
		if ( ! get_option( 'fw_ext_membership_crm_fields_v1' ) ) {
			self::define_fields();
		}
		$live    = array();
		$waiting = false;
		$since   = '';
		$renews  = '';
		$ends    = '';
		foreach ( $memberships as $m ) {
			if ( $m['started_at'] && ( '' === $since || $m['started_at'] < $since ) ) {
				$since = $m['started_at'];
			}
			if ( 'pending' === $m['status'] ) {
				$waiting = true;
			}
			if ( ! in_array( $m['status'], FW_Membership_Memberships::ACCESS_STATUSES, true ) ) {
				continue;
			}
			$plan   = FW_Membership_Plans::get( $m['plan_id'] );
			$live[] = $plan ? $plan['name'] : '#' . $m['plan_id'];
			if ( $m['renews_at'] && 'cancelled' !== $m['status'] && ( '' === $renews || $m['renews_at'] < $renews ) ) {
				$renews = $m['renews_at'];
			}
			if ( $m['ends_at'] && ( '' === $ends || $m['ends_at'] > $ends ) && ( ! $m['renews_at'] || 'cancelled' === $m['status'] ) ) {
				$ends = $m['ends_at'];
			}
		}
		$cur   = class_exists( 'FW_Membership_Gateways' ) ? FW_Membership_Gateways::currency() : '';
		$sums  = self::paid_sums( $user_id );
		$total = $cur && isset( $sums[ $cur ] ) ? $sums[ $cur ] : ( $sums ? (float) reset( $sums ) : 0.0 );
		FW_CRM_Fields::set_values( 'contact', (int) $contact_id, array(
			'membership_status'     => $live ? __( 'Member', 'fw' ) : ( $waiting ? __( 'Waiting', 'fw' ) : ( $memberships ? __( 'Former member', 'fw' ) : null ) ),
			'membership_plans'      => $live ? implode( ', ', array_unique( $live ) ) : null,
			'membership_since'      => '' !== $since ? substr( $since, 0, 10 ) : null,
			'membership_renews_at'  => '' !== $renews ? substr( $renews, 0, 10 ) : null,
			'membership_ends_at'    => '' !== $ends ? substr( $ends, 0, 10 ) : null,
			'membership_total_paid' => $total > 0 ? round( $total, 2 ) : null,
		) );
	}

	/** @internal A payment changes the total paid. */
	public static function _payment( $p ) {
		if ( $p && FW_Membership_CRM::settings()['fields'] ) {
			FW_Membership_CRM_Sync::sync_user( (int) $p['user_id'] );
		}
	}

	/** @internal */
	public static function _card( $cards ) {
		if ( ! current_user_can( FW_Membership_Service::capability() ) ) {
			return $cards;
		}
		$cards['membership'] = array(
			'title'    => __( 'Members', 'fw' ),
			'priority' => 30,
			'column'   => 'side',
			'callback' => function () {
				global $wpdb;
				$t     = FW_Membership_Installer::table( 'memberships' );
				$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
				$new   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE started_at >= %s AND status <> 'pending'", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				$left  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'expired' AND ends_at >= %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				echo '<p><strong>' . esc_html( number_format_i18n( FW_Membership_Memberships::count_members() ) ) . '</strong> ' . esc_html__( 'members with access', 'fw' ) . '</p>';
				echo '<p>' . esc_html( sprintf( __( 'Last 30 days: %1$d joined, %2$d left', 'fw' ), $new, $left ) ) . '</p>';
				if ( class_exists( 'FW_Membership_Payments' ) ) {
					$parts = array();
					foreach ( FW_Membership_Payments::totals( $since ) as $cur => $sum ) {
						$parts[] = FW_Membership_Plans::money( $sum, $cur );
					}
					if ( $parts ) {
						echo '<p>' . esc_html( sprintf( __( 'Taken: %s', 'fw' ), implode( ', ', $parts ) ) ) . '</p>';
					}
				}
				echo '<p><a href="' . esc_url( FW_Membership_Admin_Page_Url::get() ) . '">' . esc_html__( 'Open Membership', 'fw' ) . ' →</a></p>';
			},
		);
		return $cards;
	}
}
