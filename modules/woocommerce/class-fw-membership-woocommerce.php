<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Products that grant plans. A product's Membership tab names the plan buying it gives
 * (product meta _fw_mbr_grant_plan). When the order is paid (processing or completed),
 * the buyer gets the plan (source woocommerce, source_ref order:<id>:<item>; once per
 * order item); guest buyers get an account made from the billing email. Refunding or
 * cancelling the order ends what it granted.
 *
 * With the WooCommerce subscriptions add-on, a subscription product's plan follows the
 * subscription: active → active, on hold → past due, pending cancel → cancelled to the
 * paid end, cancelled / expired → ended.
 */
class FW_Membership_WooCommerce {

	const META_PLAN = '_fw_mbr_grant_plan';
	const ITEM_META = '_fw_mbr_membership_id';

	public static function init() {
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'grant_for_order' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'grant_for_order' ), 20 );
		foreach ( array( 'refunded', 'cancelled', 'failed' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'revoke_for_order' ), 20 );
		}
		add_action( 'woocommerce_subscription_status_updated', array( __CLASS__, '_subscription' ), 20, 3 );
		if ( is_admin() ) {
			add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, '_tab' ) );
			add_action( 'woocommerce_product_data_panels', array( __CLASS__, '_panel' ) );
			add_action( 'woocommerce_process_product_meta', array( __CLASS__, '_save' ) );
		}
	}

	/** @return int The plan a product grants (0 = none). A variation uses its parent's. */
	public static function plan_for_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return 0;
		}
		$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$pid = (int) get_post_meta( $id, self::META_PLAN, true );
		return $pid && FW_Membership_Plans::get( $pid ) ? $pid : 0;
	}

	/**
	 * The buyer's account (made for a guest order from the billing email).
	 *
	 * @param WC_Order $order
	 *
	 * @return int
	 */
	private static function customer( $order ) {
		$uid = (int) $order->get_customer_id();
		if ( $uid ) {
			return $uid;
		}
		$email = (string) $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return 0;
		}
		$u = get_user_by( 'email', $email );
		if ( $u ) {
			return (int) $u->ID; // the plan goes to the account with this email; they log in to use it
		}
		$uid = wc_create_new_customer( $email, '', '', array( 'first_name' => $order->get_billing_first_name(), 'last_name' => $order->get_billing_last_name() ) );
		if ( is_wp_error( $uid ) ) {
			return 0;
		}
		$order->set_customer_id( $uid );
		$order->save();
		return (int) $uid;
	}

	/**
	 * Give the plans an order bought (once per item).
	 *
	 * @param int $order_id
	 */
	public static function grant_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			$plan_id = self::plan_for_product( $product );
			if ( ! $plan_id || wc_get_order_item_meta( $item_id, self::ITEM_META, true ) ) {
				continue;
			}
			if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) && class_exists( 'WC_Subscriptions_Product' ) && WC_Subscriptions_Product::is_subscription( $product ) ) {
				continue; // the subscription's own status decides (below)
			}
			$uid = self::customer( $order );
			if ( ! $uid ) {
				$order->add_order_note( __( 'Membership: no account to give the plan to (no valid billing email).', 'fw' ) );
				continue;
			}
			$held = FW_Membership_Memberships::query( array( 'user_id' => $uid, 'plan_id' => $plan_id, 'status' => 'access', 'per_page' => 1 ) );
			if ( $held ) {
				// Buying it again extends what they hold.
				FW_Membership_Service::extend( $held[0]['id'] );
				$mid = $held[0]['id'];
			} else {
				$mid = FW_Membership_Service::grant( $uid, $plan_id, array( 'source' => 'woocommerce', 'source_ref' => 'order:' . $order_id . ':' . $item_id, 'notify' => true ) );
			}
			if ( is_wp_error( $mid ) ) {
				$order->add_order_note( sprintf( __( 'Membership: %s', 'fw' ), $mid->get_error_message() ) );
				continue;
			}
			wc_update_order_item_meta( $item_id, self::ITEM_META, (int) $mid );
			$plan = FW_Membership_Plans::get( $plan_id );
			$order->add_order_note( sprintf( __( 'Membership: %s given.', 'fw' ), $plan ? $plan['name'] : '#' . $plan_id ) );
		}
	}

	/**
	 * End what an order granted (refunded, cancelled, failed).
	 *
	 * @param int $order_id
	 */
	public static function revoke_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			$mid = (int) wc_get_order_item_meta( $item_id, self::ITEM_META, true );
			$m   = $mid ? FW_Membership_Memberships::get( $mid ) : null;
			if ( $m && 'woocommerce' === $m['source'] && 0 === strpos( (string) $m['source_ref'], 'order:' . $order_id . ':' ) && in_array( $m['status'], FW_Membership_Memberships::ACCESS_STATUSES, true ) ) {
				FW_Membership_Service::expire( $mid, sprintf( __( 'Order #%1$d was %2$s.', 'fw' ), $order_id, $order->get_status() ) );
				$order->add_order_note( __( 'Membership: ended with the order.', 'fw' ) );
			}
		}
	}

	/** @internal A subscription changed status. */
	public static function _subscription( $sub, $new, $old ) {
		if ( ! is_object( $sub ) || ! method_exists( $sub, 'get_items' ) ) {
			return;
		}
		foreach ( $sub->get_items() as $item ) {
			$plan_id = self::plan_for_product( $item->get_product() );
			if ( ! $plan_id ) {
				continue;
			}
			$uid = (int) $sub->get_customer_id();
			$ref = 'wcs:' . $sub->get_id();
			$m   = null;
			foreach ( FW_Membership_Memberships::query( array( 'user_id' => $uid, 'plan_id' => $plan_id, 'per_page' => 20 ) ) as $row ) {
				if ( $ref === $row['source_ref'] ) {
					$m = $row;
					break;
				}
			}
			$next = (string) $sub->get_date( 'next_payment' );
			$end  = $next ? get_date_from_gmt( $next ) : null;
			switch ( $new ) {
				case 'active':
					if ( ! $m ) {
						$mid = FW_Membership_Service::grant( $uid, $plan_id, array( 'source' => 'woocommerce', 'source_ref' => $ref, 'notify' => true, 'ends' => $end ? $end : 'never' ) );
					} elseif ( in_array( $m['status'], array( 'past_due', 'cancelled', 'paused' ), true ) ) {
						'paused' === $m['status'] ? FW_Membership_Service::resume( $m['id'] ) : FW_Membership_Service::reactivate( $m['id'] );
						FW_Membership_Memberships::update( $m['id'], array( 'status' => 'active', 'ends_at' => $end ) );
						FW_Membership_Service::flush_cache();
					} else {
						FW_Membership_Service::renew_until( $m['id'], $end, $end );
					}
					break;
				case 'on-hold':
					if ( $m ) {
						FW_Membership_Service::payment_failed( $m['id'], __( 'The subscription is on hold.', 'fw' ) );
					}
					break;
				case 'pending-cancel':
					if ( $m ) {
						$paid_end = (string) $sub->get_date( 'end' );
						FW_Membership_Memberships::update( $m['id'], array( 'ends_at' => $paid_end ? get_date_from_gmt( $paid_end ) : $m['ends_at'] ) );
						FW_Membership_Service::cancel( $m['id'] );
					}
					break;
				case 'cancelled':
				case 'expired':
					if ( $m && 'expired' !== $m['status'] ) {
						FW_Membership_Service::expire( $m['id'], __( 'The subscription ended.', 'fw' ) );
					}
					break;
			}
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Product Membership tab
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _tab( $tabs ) {
		$tabs['fw_membership'] = array( 'label' => __( 'Membership', 'fw' ), 'target' => 'fw_mbr_product_data', 'class' => array(), 'priority' => 75 );
		return $tabs;
	}

	/** @internal */
	public static function _panel() {
		global $post;
		$grant = (int) get_post_meta( $post->ID, self::META_PLAN, true );
		$only  = array_map( 'intval', (array) get_post_meta( $post->ID, FW_Membership_Woo_Perks::META_ONLY, true ) );
		?>
		<div id="fw_mbr_product_data" class="panel woocommerce_options_panel hidden">
			<?php wp_nonce_field( 'fw_mbr_product', '_fw_mbr_product_nonce' ); ?>
			<p class="form-field">
				<label for="fw_mbr_grant_plan"><?php esc_html_e( 'Buying it gives', 'fw' ); ?></label>
				<select id="fw_mbr_grant_plan" name="fw_mbr_grant_plan">
					<option value="0"><?php esc_html_e( 'No membership', 'fw' ); ?></option>
					<?php foreach ( FW_Membership_Plans::all( 'active' ) as $p ) : ?>
						<?php if ( FW_Membership_Plans::is_item( $p ) ) { continue; } ?>
						<option value="<?php echo (int) $p['id']; ?>" <?php selected( $grant, $p['id'] ); ?>><?php echo esc_html( $p['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="description"><?php esc_html_e( 'Given when the order is paid; ended if it is refunded or cancelled. For a subscription product it follows the subscription.', 'fw' ); ?></span>
			</p>
			<fieldset class="form-field">
				<legend><?php esc_html_e( 'Only members of these plans can buy it', 'fw' ); ?></legend>
				<?php foreach ( FW_Membership_Plans::all( 'active' ) as $p ) : ?>
					<?php if ( FW_Membership_Plans::is_item( $p ) ) { continue; } ?>
					<label style="display:block;margin-left:0"><input type="checkbox" name="fw_mbr_only[]" value="<?php echo (int) $p['id']; ?>" <?php checked( in_array( (int) $p['id'], $only, true ) ); ?> /> <?php echo esc_html( $p['name'] ); ?></label>
				<?php endforeach; ?>
				<span class="description"><?php esc_html_e( 'None ticked: anyone can buy it. To hide the product itself from non-members, use an access rule.', 'fw' ); ?></span>
			</fieldset>
		</div>
		<?php
	}

	/** @internal */
	public static function _save( $post_id ) {
		if ( ! isset( $_POST['_fw_mbr_product_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_fw_mbr_product_nonce'] ) ), 'fw_mbr_product' ) ) {
			return;
		}
		update_post_meta( $post_id, self::META_PLAN, (int) ( $_POST['fw_mbr_grant_plan'] ?? 0 ) );
		update_post_meta( $post_id, FW_Membership_Woo_Perks::META_ONLY, array_values( array_filter( array_map( 'intval', (array) ( $_POST['fw_mbr_only'] ?? array() ) ) ) ) );
	}
}
