<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Member perks in the store, set per plan (Plans → a plan → Options):
 *
 *   woo_discount       % off for members (the best of the plans they hold)
 *   woo_discount_cats  product category ids it applies to (empty = every product)
 *   woo_free_shipping  members pay no shipping
 *
 * and per product (the product's Membership tab): "Only members of these plans can buy
 * it" (_fw_mbr_only). The discount is a member price: shown struck through against the
 * normal price in the shop, the cart and the order. Products a rule hides stay hidden by
 * the access engine as any other content.
 */
class FW_Membership_Woo_Perks {

	const META_ONLY = '_fw_mbr_only';

	/** @var array|null The current person's perks (per request). */
	private static $perks = null;

	public static function init() {
		add_action( 'unysonplus_membership_plan_option_fields', array( __CLASS__, '_plan_fields' ) );
		add_filter( 'unysonplus_membership_plan_settings', array( __CLASS__, '_plan_save' ), 10, 2 );
		foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_sale_price' ) as $f ) {
			add_filter( $f, array( __CLASS__, '_price' ), 50, 2 );
		}
		add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, '_price' ), 50, 2 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, '_price' ), 50, 2 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, '_hash' ), 50 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, '_shipping' ), 50 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, '_purchasable' ), 50, 2 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, '_only_notice' ), 31 );
		add_action( 'unysonplus_membership_changed', array( __CLASS__, 'flush' ) );
		add_filter( 'woocommerce_sale_flash', array( __CLASS__, '_flash' ), 50, 3 );
	}

	/** @internal The badge says "Member price" when the lower price is the member's, not a sale. */
	public static function _flash( $html, $post, $product ) {
		if ( ! $product instanceof WC_Product || ! self::applies( $product, self::perks() ) ) {
			return $html;
		}
		$sale = $product->get_sale_price( 'edit' );
		if ( '' !== (string) $sale && (float) $sale <= (float) $product->get_price() ) {
			return $html; // a real sale is at least as good: keep its badge
		}
		return '<span class="onsale">' . esc_html__( 'Member price', 'fw' ) . '</span>';
	}

	public static function flush() {
		self::$perks = null;
	}

	/** @return array { discount (%), cats int[] (empty = all), free_shipping bool } */
	public static function perks( $user_id = null ) {
		$own = null === $user_id;
		if ( $own && null !== self::$perks ) {
			return self::$perks;
		}
		$plans = $own ? FW_Membership_Access::persona()['plans'] : FW_Membership_Service::user_plans( (int) $user_id );
		$out   = array( 'discount' => 0.0, 'cats' => array(), 'free_shipping' => false );
		foreach ( $plans as $pid ) {
			$p = FW_Membership_Plans::get( $pid );
			if ( ! $p ) {
				continue;
			}
			$d = (float) ( $p['settings']['woo_discount'] ?? 0 );
			if ( $d > $out['discount'] ) {
				$out['discount'] = $d;
				$out['cats']     = array_map( 'intval', (array) ( $p['settings']['woo_discount_cats'] ?? array() ) );
			}
			$out['free_shipping'] = $out['free_shipping'] || ! empty( $p['settings']['woo_free_shipping'] );
		}
		if ( $own ) {
			self::$perks = $out;
		}
		return $out;
	}

	/** @return bool Does the member discount apply to this product? */
	public static function applies( $product, array $perks ) {
		if ( $perks['discount'] <= 0 || ! $product instanceof WC_Product ) {
			return false;
		}
		if ( ! $perks['cats'] ) {
			return true;
		}
		$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		return (bool) array_intersect( $perks['cats'], wc_get_product_term_ids( $id, 'product_cat' ) );
	}

	/** @internal The member price. */
	public static function _price( $price, $product ) {
		if ( '' === $price || null === $price || is_admin() && ! wp_doing_ajax() ) {
			return $price;
		}
		$perks = self::perks();
		if ( ! self::applies( $product, $perks ) ) {
			return $price;
		}
		$regular = (float) $product->get_regular_price( 'edit' );
		$base    = $regular > 0 ? $regular : (float) $price;
		$member  = round( $base * ( 1 - $perks['discount'] / 100 ), wc_get_price_decimals() );
		return (string) min( (float) $price, $member ); // never dearer than a sale already running
	}

	/** @internal Variation prices are cached; the cache must know who is looking. */
	public static function _hash( $hash ) {
		$hash[] = 'fw_mbr_' . md5( wp_json_encode( self::perks() ) );
		return $hash;
	}

	/** @internal */
	public static function _shipping( $rates ) {
		if ( ! self::perks()['free_shipping'] ) {
			return $rates;
		}
		foreach ( $rates as $rate ) {
			if ( method_exists( $rate, 'set_cost' ) ) {
				$rate->set_cost( 0 );
				$rate->set_taxes( array() );
				$rate->set_label( $rate->get_label() . ' ' . __( '(free for members)', 'fw' ) );
			}
		}
		return $rates;
	}

	/** @return int[] The plans allowed to buy a product (empty = anyone). */
	public static function only_for( $product ) {
		$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		return array_map( 'intval', (array) get_post_meta( $id, self::META_ONLY, true ) );
	}

	/** @internal */
	public static function _purchasable( $ok, $product ) {
		if ( ! $ok || ! $product instanceof WC_Product ) {
			return $ok;
		}
		$only = self::only_for( $product );
		if ( ! $only || current_user_can( FW_Membership_Service::capability() ) ) {
			return $ok;
		}
		return (bool) array_intersect( $only, FW_Membership_Access::persona()['plans'] );
	}

	/** @internal "Members only" under the price. */
	public static function _only_notice() {
		global $product;
		if ( ! $product instanceof WC_Product || $product->is_purchasable() ) {
			return;
		}
		$only = self::only_for( $product );
		if ( ! $only ) {
			return;
		}
		$names = array();
		foreach ( $only as $pid ) {
			$p = FW_Membership_Plans::get( $pid );
			if ( $p ) {
				$names[] = $p['name'];
			}
		}
		FW_Membership_Denied::enqueue();
		echo '<p class="fw-mbr-notice">' . esc_html( sprintf( __( 'For members of %s.', 'fw' ), implode( ', ', $names ) ) ) . ( ! is_user_logged_in() ? ' <a href="' . esc_url( FW_Membership_Settings::login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', 'fw' ) . '</a>' : '' ) . '</p>';
	}

	/** @internal */
	public static function _plan_fields( $plan ) {
		$s    = (array) $plan['settings'];
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		?>
		<p class="fw-mbr-field">
			<label for="fw-mbr-woo-discount"><?php esc_html_e( 'Store discount for members (%)', 'fw' ); ?></label>
			<input type="number" min="0" max="100" step="0.5" id="fw-mbr-woo-discount" name="woo_discount" value="<?php echo esc_attr( (string) ( $s['woo_discount'] ?? 0 ) ); ?>" />
			<span class="description"><?php esc_html_e( 'Members see it as a member price. The best discount of the plans they hold applies.', 'fw' ); ?></span>
		</p>
		<?php if ( ! is_wp_error( $cats ) && $cats ) : ?>
			<fieldset class="fw-mbr-field">
				<legend><?php esc_html_e( 'On products in', 'fw' ); ?></legend>
				<?php foreach ( $cats as $c ) : ?>
					<label class="fw-mbr-check"><input type="checkbox" name="woo_discount_cats[]" value="<?php echo (int) $c->term_id; ?>" <?php checked( in_array( (int) $c->term_id, array_map( 'intval', (array) ( $s['woo_discount_cats'] ?? array() ) ), true ) ); ?> /> <?php echo esc_html( $c->name ); ?></label>
				<?php endforeach; ?>
				<span class="description"><?php esc_html_e( 'None ticked: every product.', 'fw' ); ?></span>
			</fieldset>
		<?php endif; ?>
		<p class="fw-mbr-field">
			<label class="fw-mbr-check"><input type="checkbox" name="woo_free_shipping" value="1" <?php checked( ! empty( $s['woo_free_shipping'] ) ); ?> /> <?php esc_html_e( 'Free shipping for members', 'fw' ); ?></label>
		</p>
		<?php
	}

	/** @internal */
	public static function _plan_save( $settings, $posted ) {
		$settings['woo_discount']      = round( max( 0, min( 100, (float) ( $posted['woo_discount'] ?? 0 ) ) ), 2 );
		$settings['woo_discount_cats'] = array_values( array_filter( array_map( 'intval', (array) ( $posted['woo_discount_cats'] ?? array() ) ) ) );
		$settings['woo_free_shipping'] = ! empty( $posted['woo_free_shipping'] );
		return $settings;
	}
}
