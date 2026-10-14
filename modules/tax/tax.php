<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Tax module (Membership → Modules → Tax). Needs the Payments module; its settings
 * are a panel in Payment settings.
 *
 *   class-fw-membership-tax.php        rates, EU VAT, the tax in a quote, Stripe tax rates
 *   class-fw-membership-countries.php  the country list and the EU member states
 */

require_once dirname( __FILE__ ) . '/class-fw-membership-countries.php';
require_once dirname( __FILE__ ) . '/class-fw-membership-tax.php';
FW_Membership_Tax::init();
