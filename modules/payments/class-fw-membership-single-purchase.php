<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Pay per item: sell one post, page or protected file on its own, without a plan.
 *
 * Each item for sale gets a hidden "item plan" (slug item-post-<id> / item-file-<id>,
 * settings item_type + item_id) with one one-off price, so the whole checkout works for
 * it unchanged: coupons, tax, invoices, card, PayPal and bank transfer. Buying it is
 * holding that plan:
 *
 *   post  unysonplus_membership_item_unlocked opens that one item for its owner, even
 *         when other rules cover it (a category for Gold members, say)
 *   file  the item plan is added to the file's "who may download it"
 *
 * Item plans never show in pricing tables, plan switching or the public plan list.
 * Access lasts for ever, or for a number of days after buying.
 */
class FW_Membership_Single_Purchase {

	const META_PLAN = '_fw_mbr_item_plan';
	const NONCE     = 'fw_mbr_sell';

	public static function init() {
		add_filter( 'unysonplus_membership_item_unlocked', array( __CLASS__, '_unlocked' ), 10, 3 );
		add_filter( 'unysonplus_membership_prompt_args', array( __CLASS__, '_prompt' ), 15, 3 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, '_box' ) );
			add_action( 'save_post', array( __CLASS__, '_save_post' ), 20, 2 );
		}
	}

	/**
	 * Put an item on sale (or change its price), or take it off sale.
	 *
	 * @param string $type    post|file
	 * @param int    $id
	 * @param string $title
	 * @param float  $amount  0 or less: not for sale (the plan is archived, buyers keep it).
	 * @param int    $days    Access length; 0 = for ever.
	 * @param string $currency
	 *
	 * @return int|WP_Error The item plan id (0 when taken off sale).
	 */
	public static function sell( $type, $id, $title, $amount, $days = 0, $currency = '' ) {
		$slug = 'item-' . $type . '-' . (int) $id;
		$plan = FW_Membership_Plans::get_by_slug( $slug );
		if ( (float) $amount <= 0 ) {
			if ( $plan ) {
				FW_Membership_Plans::save( array_merge( $plan, array( 'status' => 'archived' ) ), $plan['prices'], $plan['id'] );
			}
			return 0;
		}
		$currency = '' !== $currency ? $currency : FW_Membership_Gateways::currency();
		$prices   = array( array( 'label' => '', 'amount' => round( (float) $amount, 2 ), 'currency' => $currency, 'billing' => 'once', 'interval_count' => 1 ) );
		if ( $plan && $plan['prices'] ) {
			$prices[0]['id'] = $plan['prices'][0]['id']; // kept when unchanged, retired and replaced when changed
		}
		return FW_Membership_Plans::save( array(
			'name'           => $title,
			'slug'           => $slug,
			'status'         => 'active',
			'priority'       => -100,
			'duration_value' => $days > 0 ? (int) $days : 0,
			'duration_unit'  => $days > 0 ? 'day' : '',
			'settings'       => array_merge( $plan ? $plan['settings'] : array(), array( 'item_type' => $type, 'item_id' => (int) $id ) ),
		), $prices, $plan ? $plan['id'] : 0 );
	}

	/**
	 * The item plan on sale for an item.
	 *
	 * @return array|null
	 */
	public static function plan_for( $type, $id ) {
		$p = FW_Membership_Plans::get_by_slug( 'item-' . $type . '-' . (int) $id );
		return $p && 'active' === $p['status'] && $p['prices'] ? $p : null;
	}

	/** @internal The buyer of an item may see it. */
	public static function _unlocked( $open, $post, $persona ) {
		if ( $open || ! $persona['plans'] ) {
			return $open;
		}
		$p = FW_Membership_Plans::get_by_slug( 'item-post-' . (int) $post->ID );
		return $p && in_array( $p['id'], $persona['plans'], true );
	}

	/** @internal "Buy this for USD 5.00" on the prompt. */
	public static function _prompt( $args, $failing, $post ) {
		if ( ! $post instanceof WP_Post || ! empty( $failing['opens_at'] ) || ! empty( $args['button_link'] ) ) {
			return $args;
		}
		$plan = self::plan_for( 'post', $post->ID );
		$url  = $plan ? FW_Membership_Gateways::checkout_url( array( 'plan' => $plan['slug'], 'price' => $plan['prices'][0]['id'] ) ) : '';
		if ( '' === $url ) {
			return $args;
		}
		$args['button_text'] = sprintf( __( 'Buy this for %s', 'fw' ), FW_Membership_Plans::money( $plan['prices'][0]['amount'], $plan['prices'][0]['currency'] ) );
		$args['button_link'] = $url;
		return $args;
	}

	/* ---------------------------------------------------------------------- *
	 * Editor box
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _box() {
		if ( ! current_user_can( FW_Membership_Service::capability() ) ) {
			return;
		}
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
			if ( 'attachment' !== $pt ) {
				add_meta_box( 'fw-mbr-sell', __( 'Sell on its own', 'fw' ), array( __CLASS__, '_render_box' ), $pt, 'side', 'low' );
			}
		}
	}

	/** @internal */
	public static function _render_box( $post ) {
		$plan = FW_Membership_Plans::get_by_slug( 'item-post-' . (int) $post->ID );
		$on   = $plan && 'active' === $plan['status'] && $plan['prices'];
		wp_nonce_field( self::NONCE, '_fw_mbr_sell_nonce' );
		?>
		<p>
			<label for="fw-mbr-sell-amount"><?php esc_html_e( 'Price', 'fw' ); ?></label><br />
			<input type="number" min="0" step="0.01" id="fw-mbr-sell-amount" name="fw_mbr_sell_amount" value="<?php echo $on ? esc_attr( (string) $plan['prices'][0]['amount'] ) : ''; ?>" style="width:7em" />
			<?php echo esc_html( $on ? $plan['prices'][0]['currency'] : FW_Membership_Gateways::currency() ); ?>
		</p>
		<p>
			<label for="fw-mbr-sell-days"><?php esc_html_e( 'Access for (days)', 'fw' ); ?></label><br />
			<input type="number" min="0" id="fw-mbr-sell-days" name="fw_mbr_sell_days" value="<?php echo $on ? (int) $plan['duration_value'] : 0; ?>" style="width:7em" />
		</p>
		<p class="description"><?php esc_html_e( 'People without access can buy this one item at the checkout (0 days = for ever). Empty: not for sale. The item still needs an access rule (or the Membership box above) to be locked.', 'fw' ); ?></p>
		<?php if ( $on ) : ?>
			<p class="description"><?php echo esc_html( sprintf( _n( '%d buyer so far.', '%d buyers so far.', FW_Membership_Memberships::count( array( 'plan_id' => $plan['id'] ) ), 'fw' ), FW_Membership_Memberships::count( array( 'plan_id' => $plan['id'] ) ) ) ); ?></p>
		<?php endif; ?>
		<?php
	}

	/** @internal */
	public static function _save_post( $post_id, $post ) {
		if ( ! isset( $_POST['_fw_mbr_sell_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_fw_mbr_sell_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( FW_Membership_Service::capability() ) ) {
			return;
		}
		$amount = isset( $_POST['fw_mbr_sell_amount'] ) ? (float) wp_unslash( $_POST['fw_mbr_sell_amount'] ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$days   = isset( $_POST['fw_mbr_sell_days'] ) ? (int) $_POST['fw_mbr_sell_days'] : 0;
		$r      = self::sell( 'post', $post_id, get_the_title( $post_id ), $amount, $days );
		if ( ! is_wp_error( $r ) ) {
			update_post_meta( $post_id, self::META_PLAN, (int) $r );
		}
	}
}
