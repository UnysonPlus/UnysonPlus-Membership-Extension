<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Bank transfer, cash or invoice: the buyer gets your payment instructions, the
 * membership waits (pending), and you mark the payment paid on Membership → Payments,
 * which starts it. One-off prices only: nothing renews by itself.
 */
class FW_Membership_Gateway_Offline extends FW_Membership_Gateway {

	public function id() {
		return 'offline';
	}

	public function title() {
		return __( 'Bank transfer or cash', 'fw' );
	}

	public function checkout_label() {
		$label = (string) $this->setting( 'label' );
		return '' !== $label ? $label : __( 'Bank transfer', 'fw' );
	}

	public function checkout_description() {
		return __( 'You get the payment details after this step. Your membership starts when the payment arrives.', 'fw' );
	}

	public function settings_fields() {
		return array(
			'label'        => array( 'label' => __( 'Name on the checkout', 'fw' ), 'type' => 'text', 'desc' => __( 'For example "Bank transfer" or "Pay at the front desk".', 'fw' ) ),
			'instructions' => array( 'label' => __( 'Payment instructions', 'fw' ), 'type' => 'textarea', 'desc' => __( 'Shown after checkout and in the email: account details, the reference to use. {reference} is replaced by the payment\'s reference.', 'fw' ) ),
		);
	}

	public function is_ready() {
		return '' !== trim( (string) $this->setting( 'instructions' ) );
	}

	public function start( array $payment, array $membership, array $price, $user, array $urls ) {
		$ref = 'M' . str_pad( (string) $payment['id'], 6, '0', STR_PAD_LEFT );
		FW_Membership_Payments::update( $payment['id'], array( 'gateway_ref' => $ref ) );
		return array(
			'done'    => true,
			'pending' => true,
			'message' => strtr( (string) $this->setting( 'instructions' ), array( '{reference}' => $ref ) ),
		);
	}
}
