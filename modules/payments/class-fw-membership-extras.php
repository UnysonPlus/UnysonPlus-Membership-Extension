<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Checkout extras: optional add-ons a buyer can tick at the checkout (a longer video, a
 * setup service, a printed copy), each with its own price.
 *
 * An extra belongs to a plan (plan settings → `extras`, so the Import / Export set-up file
 * carries it) and is either:
 *   - `once`: paid with the first payment only (on a repeating price it is added to the
 *     setup fee, on a one-time price to the amount), or
 *   - `each`: paid every billing period (added to the repeating amount, so every renewal
 *     includes it; on a one-time price it is simply paid once).
 *
 * The chosen extras are applied to the price before the quote (FW_Membership_Checkout::requested()),
 * so the coupon and tax modules, the totals and every gateway see the real amounts without
 * knowing about extras. The payment (meta `extras`) and the membership (meta `extras`) record
 * what was bought; the invoice, the checkout summary and the member's record list them.
 *
 * A **slider** extra (`type` = `range`) lets the buyer choose an amount instead of ticking a box: a
 * video length, a number of seats. It starts at the value the plan already includes (`start`),
 * moves in `step`s up to `max`, and costs `amount` for every step above `start`; `format` says
 * whether the value is a time (seconds, shown m:ss) or a count (shown with `unit`).
 *
 * Request: `extras[]=<id>` (or `extras=<id>,<id>`) for a tick-box extra and
 * `extras_qty[<id>]=<value>` for a slider, next to `plan` and `price`.
 */
class FW_Membership_Extras {

	/** Extras a plan may offer. */
	const MAX = 12;

	public static function init() {
		add_filter( 'unysonplus_membership_plan_settings', array( __CLASS__, '_save' ), 10, 3 );
		add_action( 'unysonplus_membership_plan_main_panels', array( __CLASS__, '_panel' ) );
	}

	/**
	 * A plan's extras, cleaned.
	 *
	 * @param array|null $plan
	 *
	 * @return array[] { id, label, description, amount, billing (once|each), type (fixed|range),
	 *                   and for a slider: start, max, step, format (time|count), unit }
	 */
	public static function for_plan( $plan ) {
		return $plan ? self::normalize( (array) ( $plan['settings']['extras'] ?? array() ) ) : array();
	}

	/**
	 * @param array $rows Raw rows (posted or stored).
	 *
	 * @return array[]
	 */
	public static function normalize( array $rows ) {
		$out  = array();
		$used = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = sanitize_key( sanitize_title( $label ) );
			}
			$base = '' !== $id ? $id : 'extra';
			$n    = 1;
			while ( isset( $used[ $id ] ) ) {
				$id = $base . '-' . ( ++$n );
			}
			$used[ $id ] = true;
			$out[]       = array(
				'id'          => $id,
				'label'       => $label,
				'description' => sanitize_text_field( (string) ( $row['description'] ?? '' ) ),
				'amount'      => round( max( 0.0, (float) str_replace( ',', '.', (string) ( $row['amount'] ?? 0 ) ) ), 2 ),
				'billing'     => 'each' === ( $row['billing'] ?? '' ) ? 'each' : 'once',
				'type'        => 'range' === ( $row['type'] ?? '' ) ? 'range' : 'fixed',
			);
			if ( 'range' === $out[ count( $out ) - 1 ]['type'] ) {
				$format = 'count' === ( $row['format'] ?? '' ) ? 'count' : 'time';
				$start  = self::parse_value( $row['start'] ?? 0, $format );
				$step   = self::parse_value( $row['step'] ?? 0, $format );
				$step   = $step > 0 ? $step : ( 'time' === $format ? 30.0 : 1.0 );
				$max    = self::parse_value( $row['max'] ?? 0, $format );
				// Whole steps from the start, at least one.
				$max = $start + max( 1, floor( ( $max - $start ) / $step + 1e-9 ) ) * $step;
				$out[ count( $out ) - 1 ] += array(
					'start'  => $start,
					'max'    => $max,
					'step'   => $step,
					'format' => $format,
					'unit'   => 'count' === $format ? sanitize_text_field( (string) ( $row['unit'] ?? '' ) ) : '',
				);
			}
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The extras this request asks for (valid ones of the plan only; none for a gift).
	 *
	 * @param array|null $plan
	 *
	 * @return array[]
	 */
	public static function chosen( $plan ) {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only selection; the POST is nonce-checked before use.
		if ( ! $plan || ! empty( $_REQUEST['gift'] ) || ( ! isset( $_REQUEST['extras'] ) && ! isset( $_REQUEST['extras_qty'] ) ) ) {
			return array();
		}
		$raw = isset( $_REQUEST['extras'] ) ? wp_unslash( $_REQUEST['extras'] ) : array();
		$qty = isset( $_REQUEST['extras_qty'] ) && is_array( $_REQUEST['extras_qty'] ) ? wp_unslash( $_REQUEST['extras_qty'] ) : array();
		// phpcs:enable
		$ids = array_map( 'sanitize_key', is_array( $raw ) ? $raw : explode( ',', (string) $raw ) );
		$out = array();
		foreach ( self::for_plan( $plan ) as $e ) {
			if ( 'range' === $e['type'] ) {
				if ( ! isset( $qty[ $e['id'] ] ) || is_array( $qty[ $e['id'] ] ) ) {
					continue;
				}
				$value = self::snap( $e, self::parse_value( $qty[ $e['id'] ], $e['format'] ) );
				$steps = (int) round( ( $value - $e['start'] ) / $e['step'] );
				if ( $steps < 1 ) {
					continue; // what the plan already includes: nothing to add
				}
				$e['value']       = $value;
				$e['steps']       = $steps;
				$e['unit_amount'] = $e['amount'];
				$e['amount']      = round( $e['amount'] * $steps, 2 );
				$e['label']       = sprintf( __( '%1$s (%2$s)', 'fw' ), $e['label'], self::format_value( $e, $value ) );
				$out[]            = $e;
			} elseif ( in_array( $e['id'], $ids, true ) ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	/**
	 * A slider value from a request or the plan editor: "1:30" or "90" for a time (seconds), a
	 * number for a count.
	 *
	 * @param mixed  $raw
	 * @param string $format time|count
	 *
	 * @return float
	 */
	public static function parse_value( $raw, $format ) {
		$raw = trim( str_replace( ',', '.', (string) $raw ) );
		if ( 'time' === $format && preg_match( '/^(\d+):(\d{1,2})$/', $raw, $m ) ) {
			return (float) ( (int) $m[1] * 60 + (int) $m[2] );
		}
		return max( 0.0, (float) $raw );
	}

	/**
	 * A value moved onto the slider's steps, between its start and its maximum.
	 *
	 * @param array $extra A slider extra.
	 * @param float $value
	 *
	 * @return float
	 */
	public static function snap( array $extra, $value ) {
		$steps = round( ( (float) $value - $extra['start'] ) / $extra['step'] );
		return min( $extra['max'], max( $extra['start'], $extra['start'] + $steps * $extra['step'] ) );
	}

	/**
	 * How a slider value reads: "3:00" for a time, "5 seats" for a count.
	 *
	 * @param array $extra
	 * @param float $value
	 *
	 * @return string
	 */
	public static function format_value( array $extra, $value ) {
		if ( 'time' === ( $extra['format'] ?? 'time' ) ) {
			$s = (int) round( $value );
			return sprintf( '%d:%02d', intdiv( $s, 60 ), $s % 60 );
		}
		$whole = abs( $value - round( $value ) ) < 0.001;
		return trim( number_format_i18n( (float) $value, $whole ? 0 : 2 ) . ' ' . ( $extra['unit'] ?? '' ) );
	}

	/**
	 * One step of a slider ("30 seconds", "1 minute", "5 seats"), for "USD 6.00 for every 30 seconds".
	 *
	 * @param array $extra
	 *
	 * @return string
	 */
	public static function step_label( array $extra ) {
		if ( 'time' === $extra['format'] ) {
			$s = (int) round( $extra['step'] );
			if ( 0 === $s % 60 ) {
				return sprintf( _n( '%d minute', '%d minutes', $s / 60, 'fw' ), $s / 60 );
			}
			return sprintf( _n( '%d second', '%d seconds', $s, 'fw' ), $s );
		}
		return self::format_value( $extra, $extra['step'] );
	}

	/**
	 * The price with the chosen extras added. The original price stays under `base`, the
	 * chosen extras under `extras`.
	 *
	 * @param array $plan
	 * @param array $price
	 * @param array $chosen From chosen().
	 *
	 * @return array
	 */
	public static function apply( array $plan, array $price, array $chosen ) {
		if ( ! $chosen || 'free' === $price['billing'] ) {
			return $price;
		}
		$once = 0.0;
		$each = 0.0;
		foreach ( $chosen as $e ) {
			if ( 'each' === $e['billing'] ) {
				$each += $e['amount'];
			} else {
				$once += $e['amount'];
			}
		}
		$base = $price;
		if ( FW_Membership_Payments::is_recurring( $price ) ) {
			$price['amount']    = round( (float) $price['amount'] + $each, 2 );
			$price['setup_fee'] = round( (float) $price['setup_fee'] + $once, 2 );
		} else {
			$price['amount'] = round( (float) $price['amount'] + $each + $once, 2 );
		}
		$price['base']   = $base;
		$price['extras'] = $chosen;
		return $price;
	}

	/**
	 * How an extra's price reads next to a price ("+ US$3.00 a month", "+ US$4.00 once").
	 *
	 * @param array $extra
	 * @param array $price The plan's (base) price.
	 *
	 * @return string
	 */
	public static function price_label( array $extra, array $price ) {
		$money = FW_Membership_Plans::money( $extra['amount'], '' !== (string) $price['currency'] ? $price['currency'] : FW_Membership_Gateways::currency() );
		if ( 'each' === $extra['billing'] && FW_Membership_Payments::is_recurring( $price ) ) {
			$per = array( 'day' => __( 'a day', 'fw' ), 'week' => __( 'a week', 'fw' ), 'month' => __( 'a month', 'fw' ), 'year' => __( 'a year', 'fw' ) );
			return sprintf( __( '+ %1$s %2$s', 'fw' ), $money, (int) $price['interval_count'] > 1 ? __( 'each renewal', 'fw' ) : ( $per[ $price['billing'] ] ?? __( 'each renewal', 'fw' ) ) );
		}
		return sprintf( __( '+ %s once', 'fw' ), $money );
	}

	/**
	 * What a payment or membership records about its extras (no ids that could drift).
	 *
	 * @param array[] $chosen
	 *
	 * @return array[] { id, label, amount, billing }
	 */
	public static function record( array $chosen ) {
		return array_map( function ( $e ) {
			$row = array( 'id' => $e['id'], 'label' => $e['label'], 'amount' => $e['amount'], 'billing' => $e['billing'] );
			if ( isset( $e['value'] ) ) {
				$row['value'] = $e['value'];
			}
			return $row;
		}, $chosen );
	}

	/**
	 * "Longer video, Your own voice" for a stored list.
	 *
	 * @param array $stored
	 *
	 * @return string
	 */
	public static function summary( $stored ) {
		return implode( ', ', array_filter( array_map( function ( $e ) {
			return is_array( $e ) ? (string) ( $e['label'] ?? '' ) : '';
		}, (array) $stored ) ) );
	}

	/* ---------------------------------------------------------------------- */

	/** @internal Plan editor → save: the posted extras into the plan settings. */
	public static function _save( $settings, $posted, $existing ) {
		$settings['extras'] = self::normalize( (array) ( $posted['extras'] ?? array() ) );
		return $settings;
	}

	/** @internal Plan editor → the Checkout extras panel. */
	public static function _panel( $plan ) {
		$rows   = self::for_plan( $plan );
		$rows[] = null; // the template row for "Add an extra"
		?>
		<section class="fw-mbr-panel" aria-labelledby="fw-mbr-h-extras">
			<header class="fw-mbr-panel__head"><h2 id="fw-mbr-h-extras"><?php esc_html_e( 'Checkout extras', 'fw' ); ?></h2></header>
			<p class="fw-mbr-muted"><?php esc_html_e( 'Optional add-ons the buyer can tick at the checkout, each with its own price: a longer version, a setup service, a printed copy. “Once” is paid with the first payment only; “Each period” is added to every payment of a repeating price (on a one-time price it is simply paid once). The totals, coupons, tax and the payment page include them. A “Slider” lets the buyer choose how much instead (a video length, a number of seats), charged per step above what the plan includes.', 'fw' ); ?></p>
			<style>#fw-mbr-extras th:nth-child(1){width:17%}#fw-mbr-extras th:nth-child(2){width:47%}#fw-mbr-extras th:nth-child(3){width:11%}#fw-mbr-extras th:nth-child(4),#fw-mbr-extras th:nth-child(5){width:10%}#fw-mbr-extras td{vertical-align:top}.fw-mbr-extra__range label{display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap}.fw-mbr-extra__range input[type=text]{width:5.5em}.fw-mbr-extra__range select{width:auto}.fw-mbr-extra__range{display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;margin-top:.6rem}.fw-mbr-extra__range[hidden],.fw-mbr-extra__unit[hidden]{display:none}.fw-mbr-extra__range .description{flex-basis:100%;margin:0}</style>
			<table class="widefat fw-mbr-prices" id="fw-mbr-extras">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Extra', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Description (shown under it)', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Amount (a slider: per step)', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Kind', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Paid', 'fw' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'fw' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $i => $e ) : ?>
						<?php
						$tpl = null === $e;
						$e   = $e ? $e : array( 'id' => '', 'label' => '', 'description' => '', 'amount' => '', 'billing' => 'once' );
						$n   = $tpl ? '__i__' : $i;
						?>
						<tr class="fw-mbr-extra<?php echo $tpl ? ' fw-mbr-extra--tpl' : ''; ?>"<?php echo $tpl ? ' hidden' : ''; ?>>
							<td>
								<input type="hidden" name="extras[<?php echo esc_attr( $n ); ?>][id]" value="<?php echo esc_attr( $e['id'] ); ?>"<?php disabled( $tpl ); ?> />
								<input type="text" name="extras[<?php echo esc_attr( $n ); ?>][label]" value="<?php echo esc_attr( $e['label'] ); ?>" placeholder="<?php esc_attr_e( 'Longer video', 'fw' ); ?>" aria-label="<?php esc_attr_e( 'Extra', 'fw' ); ?>"<?php disabled( $tpl ); ?> />
							</td>
							<td>
								<input type="text" class="widefat" name="extras[<?php echo esc_attr( $n ); ?>][description]" value="<?php echo esc_attr( $e['description'] ); ?>" placeholder="<?php esc_attr_e( 'Up to 60 seconds instead of 30', 'fw' ); ?>" aria-label="<?php esc_attr_e( 'Description', 'fw' ); ?>"<?php disabled( $tpl ); ?> />
								<?php
								$is_range = 'range' === ( $e['type'] ?? 'fixed' );
								$rfmt     = $e['format'] ?? 'time';
								$rv       = function ( $k ) use ( $e, $is_range ) {
									return $is_range ? self::format_value( array( 'format' => $e['format'] ), $e[ $k ] ) : '';
								};
								?>
								<div class="fw-mbr-extra__range"<?php echo $is_range ? '' : ' hidden'; ?>>
									<label><?php esc_html_e( 'Shown as', 'fw' ); ?>
										<select name="extras[<?php echo esc_attr( $n ); ?>][format]"<?php disabled( $tpl ); ?>>
											<option value="time" <?php selected( $rfmt, 'time' ); ?>><?php esc_html_e( 'A time (minutes:seconds)', 'fw' ); ?></option>
											<option value="count" <?php selected( $rfmt, 'count' ); ?>><?php esc_html_e( 'A number', 'fw' ); ?></option>
										</select>
									</label>
									<label><?php esc_html_e( 'Included', 'fw' ); ?> <input type="text" size="6" name="extras[<?php echo esc_attr( $n ); ?>][start]" value="<?php echo esc_attr( $rv( 'start' ) ); ?>" placeholder="1:30"<?php disabled( $tpl ); ?> /></label>
									<label><?php esc_html_e( 'Up to', 'fw' ); ?> <input type="text" size="6" name="extras[<?php echo esc_attr( $n ); ?>][max]" value="<?php echo esc_attr( $rv( 'max' ) ); ?>" placeholder="5:00"<?php disabled( $tpl ); ?> /></label>
									<label><?php esc_html_e( 'Step', 'fw' ); ?> <input type="text" size="6" name="extras[<?php echo esc_attr( $n ); ?>][step]" value="<?php echo esc_attr( $rv( 'step' ) ); ?>" placeholder="0:30"<?php disabled( $tpl ); ?> /></label>
									<label class="fw-mbr-extra__unit"<?php echo 'count' === $rfmt ? '' : ' hidden'; ?>><?php esc_html_e( 'Unit', 'fw' ); ?> <input type="text" size="8" name="extras[<?php echo esc_attr( $n ); ?>][unit]" value="<?php echo esc_attr( $e['unit'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'seats', 'fw' ); ?>"<?php disabled( $tpl ); ?> /></label>
									<p class="description"><?php esc_html_e( 'The buyer drags from “Included” (no charge) up to “Up to”, in steps. The amount is charged for every step above what is included. Times are written as 1:30 or in seconds.', 'fw' ); ?></p>
								</div>
							</td>
							<td><input type="number" min="0" step="0.01" name="extras[<?php echo esc_attr( $n ); ?>][amount]" value="<?php echo esc_attr( '' === $e['amount'] ? '' : number_format( (float) $e['amount'], 2, '.', '' ) ); ?>" aria-label="<?php esc_attr_e( 'Amount', 'fw' ); ?>"<?php disabled( $tpl ); ?> /></td>
							<td>
								<select class="fw-mbr-extra__type" name="extras[<?php echo esc_attr( $n ); ?>][type]" aria-label="<?php esc_attr_e( 'Kind', 'fw' ); ?>"<?php disabled( $tpl ); ?>>
									<option value="fixed" <?php selected( $e['type'] ?? 'fixed', 'fixed' ); ?>><?php esc_html_e( 'Tick-box', 'fw' ); ?></option>
									<option value="range" <?php selected( $e['type'] ?? 'fixed', 'range' ); ?>><?php esc_html_e( 'Slider', 'fw' ); ?></option>
								</select>
							</td>
							<td>
								<select name="extras[<?php echo esc_attr( $n ); ?>][billing]" aria-label="<?php esc_attr_e( 'Paid', 'fw' ); ?>"<?php disabled( $tpl ); ?>>
									<option value="once" <?php selected( $e['billing'], 'once' ); ?>><?php esc_html_e( 'Once', 'fw' ); ?></option>
									<option value="each" <?php selected( $e['billing'], 'each' ); ?>><?php esc_html_e( 'Each period', 'fw' ); ?></option>
								</select>
							</td>
							<td><button type="button" class="button-link fw-mbr-extra__remove" aria-label="<?php esc_attr_e( 'Remove this extra', 'fw' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="fw-mbr-extra-add"><?php esc_html_e( 'Add an extra', 'fw' ); ?></button></p>
			<script>
			( function () {
				var table = document.getElementById( 'fw-mbr-extras' ), add = document.getElementById( 'fw-mbr-extra-add' );
				if ( ! table || ! add ) { return; }
				var body = table.tBodies[ 0 ], tpl = body.querySelector( '.fw-mbr-extra--tpl' ), next = body.rows.length;
				function sync() { table.hidden = ! body.querySelectorAll( '.fw-mbr-extra:not(.fw-mbr-extra--tpl)' ).length; }
				add.addEventListener( 'click', function () {
					var row = tpl.cloneNode( true );
					row.hidden = false;
					row.classList.remove( 'fw-mbr-extra--tpl' );
					row.querySelectorAll( '[name]' ).forEach( function ( el ) { el.name = el.name.replace( '__i__', String( next ) ); el.disabled = false; } );
					next++;
					body.insertBefore( row, tpl );
					sync();
					row.querySelector( 'input[type=text]' ).focus();
				} );
				// A slider shows its own settings; a number shows its unit.
				body.addEventListener( 'change', function ( e ) {
					var row = e.target.closest( 'tr' );
					if ( e.target.classList.contains( 'fw-mbr-extra__type' ) ) {
						row.querySelector( '.fw-mbr-extra__range' ).hidden = 'range' !== e.target.value;
					}
					if ( /\[format\]$/.test( e.target.name || '' ) ) {
						row.querySelector( '.fw-mbr-extra__unit' ).hidden = 'count' !== e.target.value;
					}
				} );
				body.addEventListener( 'click', function ( e ) {
					var btn = e.target.closest( '.fw-mbr-extra__remove' );
					if ( btn ) { btn.closest( 'tr' ).remove(); sync(); add.focus(); }
				} );
				sync();
			}() );
			</script>
		</section>
		<?php
	}
}
