<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The CRM module: on by itself while the CRM extension is active (see
 * FW_Membership_Modules), loaded only then. Uses the CRM's extension API only.
 *
 *   class-fw-membership-crm.php              the bridge: settings (Membership → Settings)
 *   class-fw-membership-crm-identity.php     one person, one contact
 *   class-fw-membership-crm-sync.php         plans mirrored to managed tags
 *   class-fw-membership-crm-conditions.php   CRM tags, lists and segments in access rules
 *   class-fw-membership-crm-timeline.php     timeline, contact panel, sidebar link, follow-up task
 *   class-fw-membership-crm-automations.php  triggers and actions for CRM automations
 *   class-fw-membership-crm-summary.php      managed contact fields and the Home card
 */

if ( ! class_exists( 'FW_CRM_Contacts' ) ) {
	return; // the CRM is not loaded (yet): nothing to connect to
}

$fw_mbr_crm_dir = dirname( __FILE__ );
foreach ( array( '', '-identity', '-sync', '-conditions', '-timeline', '-automations', '-summary' ) as $fw_mbr_crm_part ) {
	require_once $fw_mbr_crm_dir . '/class-fw-membership-crm' . $fw_mbr_crm_part . '.php';
}
FW_Membership_CRM::init();
FW_Membership_CRM_Identity::init();
FW_Membership_CRM_Sync::init();
FW_Membership_CRM_Conditions::init();
FW_Membership_CRM_Timeline::init();
FW_Membership_CRM_Automations::init();
FW_Membership_CRM_Summary::init();
