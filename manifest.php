<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Membership', 'fw' );
$manifest['slug']        = 'unysonplus-membership';
$manifest['description'] = __(
	'Members-only content for WordPress: plans, membership records, one access engine for pages, elements and menus, a member area designed in the page builder, and switchable modules for payments, drip, groups, retention and courses. Connects to the CRM when it is on.',
	'fw'
);

/**
 * Changelog ----------------------------------------------------------------
 *
 * 1.0.23 - Webhooks and add-on modules. A Webhooks module (Membership -> Webhooks)
 *         posts every membership event, payments and course events as signed JSON
 *         (X-Webhook-Signature t=..,v1=HMAC-SHA256 of "t.body", as the other Unyson+
 *         webhooks) to the addresses you add, from a queue worked in the background,
 *         retrying with backoff and switching a failing endpoint off; with a test button,
 *         per-webhook events and secret rotation. Modules from a plugin or theme now load:
 *         an entry added with the unysonplus_membership_modules filter gives `file`, the
 *         loader to include while it is on. FW_Membership_Webhooks::emit() and the
 *         unysonplus_membership_webhook_events filter let a module send its own events.
 *
 * 1.0.22 - The AI Assistant can run a membership site. Eleven more abilities: make the
 *         member pages (elements placed in the builder, settings pointed at them), switch
 *         modules, find members and give / extend / change / cancel / end / reactivate a
 *         membership, payment settings (secret keys are refused: the owner pastes them),
 *         coupons, a course with its lessons and quizzes, selling one item, member emails,
 *         profile fields, and the Reports totals. Saving a plan now takes its options
 *         (plans members may switch to, redirects, role, team seats, devices, store perks)
 *         and a rule its drip delay; an updated rule keeps its priority.
 *
 * 1.0.21 - A way to join from every prompt. Settings -> Member pages has a Join page (the
 *         page with the Pricing Plans element); "This is for members" prompts now show
 *         a Join button (See the plans when logged in) next to Log in, going there, or
 *         to the checkout when one plan unlocks the content. A gift code typed into the
 *         checkout's Have a code? box opens its redeem page, and the Pricing Plans
 *         element has a Gift link text option (empty hides the gift link).
 *
 * 1.0.20 - Export members to CSV. Members -> Export CSV downloads the memberships the
 *         list shows (its status, plan and search filters apply), one row per
 *         membership, starting with exactly the columns the importer reads, so a file
 *         exported from one site imports into another with subscriptions still linked.
 *         Further columns are for reading (ids, names, source, trial and cancel dates).
 *         Cells that would run as spreadsheet formulas are escaped. Also on the command
 *         line: wp membership export [file] [--plan] [--status]. Filters
 *         unysonplus_membership_export_columns / _export_row add columns.
 *
 * 1.0.16 - Courses (phase 11). A Courses module: courses and lessons as their own
 *         post types, grouped into modules and ordered in a lesson list. Who may take
 *         a course is set on the course (plans, any logged-in member, or open), and a
 *         managed access rule keeps its lessons behind it; a course sold on its own
 *         lets buyers in too. Students mark lessons complete, see a progress bar and
 *         ticks in the outline, pick up where they left off, and find their courses on
 *         the account page. Lessons can open a set number of days after starting.
 *         Quizzes are written in a plain text format (single choice, tick all that
 *         apply, typed answer, true or false), with a pass mark, and can be required
 *         before the next lesson opens. A course can give a certificate on completion:
 *         a built-in design or a page made in the builder with {name} {course} {date}
 *         {code} {verify_url}, a link anyone can check, printable as PDF, and emailed.
 *         A Course Outline element shows the outline anywhere.
 *
 * 1.0.15 - Security and operations (phase 10).
 *         - Account security module: a limit on devices signed in at once per plan
 *           (one too many signs out the oldest); one-click login links by email
 *           (single use, 15 minutes, a confirm button so mail scanners cannot use them);
 *           passkeys (WebAuthn: fingerprint, face or device PIN), added on the account
 *           page and offered on every login form, verified with no library (CBOR, COSE
 *           ES256 / RS256, challenge, origin, site id, signature, sign counter); and a
 *           log of logins, failed logins, denied pages and plan changes on each member's
 *           page, with "used from N places" to spot shared accounts (IPs only hashed).
 *         - Importers module: a CSV import with column matching and a dry run that
 *           keeps payment-service subscription IDs (so renewals carry on), and plans for
 *           everyone with a role or a user-field value.
 *         - Scheduled jobs card on Home and in Site Health, with the cron line to add.
 *         - WP-CLI: wp membership list | plans | grant | extend | expire | reconcile.
 *
 * 1.0.14 - Reports (phase 9). Membership -> Reports works the numbers out from the
 *         memberships, their history and the payments: monthly recurring revenue (now,
 *         by plan, and a 12-month chart), month by month new members, upgrades,
 *         downgrades, members lost, churn, failed and recovered payments and revenue,
 *         lifetime value, trial conversion, retention by joining month (a cohort grid)
 *         and by how people came, and the most-read members-only content (counted per
 *         item and month, with no record of who). CSV download. Amounts are never added
 *         across currencies.
 *
 * 1.0.13 - WooCommerce (phase 8). A product's Membership tab names the plan buying it
 *         gives: it starts when the order is paid (an account is made for a guest
 *         buyer) and ends when the order is refunded, cancelled or fails; a
 *         subscription product's plan follows the subscription. Plans get store perks
 *         (a member discount on every product or chosen categories, shown as a member
 *         price, and free shipping), and a product can be buyable only by members of
 *         chosen plans.
 *
 * 1.0.12 - Groups and community (phase 7).
 *         - Groups and teams module: a plan with "Team seats" makes its holder a team
 *           owner who invites people by email, a join link or their email domain; each
 *           member holds the same plan, which follows the owner's (renewal, pause,
 *           cancel, end). Owners manage the team on their account page; members can
 *           leave; Membership -> Groups lists every team.
 *         - Member directory module: the Member Directory element (search, filters,
 *           profile pages) lists members who chose to be listed (or every member who
 *           has not opted out); each profile field says who sees it (not shown,
 *           members, everyone) and only visible fields can be searched or filtered.
 *         - Membership cards module: a card with a QR code on the account page and full
 *           screen; scanning it shows "valid / not valid" to anyone and, to staff, who
 *           it is with a Check in button (kept in the member's history).
 *         Core: plan form hooks (unysonplus_membership_plan_option_fields /
 *         _plan_settings), the account page's guest takeover, Service::flush_cache().
 *
 * 1.0.11 - Keeping members (phase 6).
 *         - Retention module: failed renewals keep access for a grace period, get
 *           follow-up emails on chosen days and a signed one-click "update your card"
 *           link ({update_card_url}), and cards that expire before the next renewal are
 *           warned about (Stripe invoice.upcoming). Members can pause for 1 to N months
 *           (Stripe resumes by itself; PayPal and plain memberships on the day, with
 *           the paused time added back). "Cancel membership" asks why, then makes one
 *           offer (a Stripe discount for some months, a pause, or a cheaper plan) before
 *           "Cancel anyway". Renewal, trial-ending and expiring reminders, and a win-back
 *           email with a one-use discount code. Membership -> Retention reports reasons,
 *           saves, pauses and recovered payments.
 *         - Gift memberships module: "Give as a gift" buys one period of a plan for
 *           someone else by email, with a message; they redeem it on the checkout page
 *           (?redeem=CODE) into a new or existing account. Membership -> Gifts.
 *         Core: Service::pause() / resume(), gateway pause / unpause / discount, hooks
 *         unysonplus_membership_checkout_start, _checkout_takeover, _account_takeover,
 *         _account_cancel_html, _email_vars, _renewal_upcoming, _payment_paid_other.
 *
 * 1.0.10 - Content tools (phase 5).
 *         - Drip content module: an access rule opens N days after the person joined
 *           or on a date; until then members see "This opens for you in N days", the
 *           account page lists what is coming up, and a daily email says what opened.
 *         - Metered paywall module: rules with "Free reads" let visitors read a few
 *           items per day, week or month (a signed cookie) and free accounts more (the
 *           registration wall), with a "N free articles left" note and a used-up prompt.
 *         - Protected downloads module: files in a random, web-denied folder under
 *           random names, served only through an access check, with monthly limits, a
 *           download log (hashed IPs) and the Member Download element.
 *         - Pay per item: a price on any post or page ("Sell on its own") or protected
 *           file sells that one item through the normal checkout, for ever or for N
 *           days, via a hidden item plan.
 *         Core: hooks unysonplus_membership_rule_opens_at, _main_denied, _prompt_args,
 *         _item_unlocked, _rule_option_fields, _settings_panels / _save_settings.
 *
 * 1.0.9 - The CRM connection (phase 4), on by itself while the CRM is active. Every
 *         member is a CRM contact (matched by email and linked to their account, never
 *         taking over a contact linked to someone else; name and email follow the
 *         account). Each plan is mirrored to a managed tag (member-<plan>), with
 *         "member" and "former-member" tags, kept in step after every change. Access
 *         rules can let in people with CRM tags, on CRM lists or in CRM segments.
 *         Membership events and payments appear on the contact's timeline, the contact
 *         page gets a Membership panel, the CRM Home a Members card, and a failed
 *         renewal can add a follow-up task. Managed contact fields (status, plans,
 *         member since, renews on, ends on, total paid) feed segments and date-based
 *         automations, and membership.* triggers and actions (give a plan, extend,
 *         cancel) are registered for CRM automations on the contract agreed with the
 *         CRM. Settings: Membership -> Settings -> CRM connection.
 *
 * 1.0.8 - Coupons, invoices, tax and PayPal: phase 3 complete.
 *         - Coupons module: percentage or fixed codes for the first payment, the first
 *           few months or every payment, per plan, with total and per-person limits,
 *           start and end dates, bulk single-use codes (CSV export) and ?coupon=CODE
 *           links. Stripe applies them as its own coupons, so renewals are discounted
 *           for exactly the right months.
 *         - Tax module: rates by country and region, prices with or without tax, EU VAT
 *           numbers checked with VIES and reverse-charged across borders, or Stripe Tax.
 *           The checkout asks for the country first and shows the exact total before
 *           any payment details.
 *         - Invoices: one unbroken number sequence with your prefix, business details,
 *           a printable invoice page members open from their account and the receipt
 *           email (and save as PDF).
 *         - PayPal: Orders for one-off prices, Subscriptions (with trials, setup fees,
 *           tax and discounts) for repeating ones, webhooks verified by PayPal.
 *         One price quote (FW_Membership_Payments_Flow::quote(), filter
 *         unysonplus_membership_quote) now decides what every checkout charges.
 *
 * 1.0.7 - Members change plan themselves. Each plan lists the plans its members may
 *         switch to (Plans -> Members can switch to), and the account page offers them
 *         under "Change plan". A Stripe subscription moves to a dearer price at once,
 *         with the difference charged now (prorated) or from the next renewal (Payment
 *         settings -> When a member upgrades); a cheaper price or a free plan takes over
 *         at the next renewal, with "Keep my current plan" to undo it. A membership
 *         without a subscription goes to a paid plan through the checkout, which ends
 *         the old membership once the new one is paid. The Pricing Plans element says
 *         "Change plan" for those plans instead of selling a second membership.
 *         Reactivating a cancelled membership in the site resumes its subscription.
 *         New: memberships.meta column (DB 1.0.1), the account_action / account_notices /
 *         account_membership hooks, and gateway switch_price() / resume().
 *
 * 1.0.6 - Payments (Membership -> Modules -> Payments). Plans can now be sold: a
 *         Pricing Plans element (monthly/yearly switch, featured plan) links to a
 *         Membership Checkout element that creates the buyer's account and hands
 *         over to a payment method. Stripe Checkout (hosted, so card details never
 *         touch the site) handles one-off and recurring prices, free trials and setup
 *         fees; Stripe Billing renews them, and signed webhooks keep the membership
 *         in step (renewed, payment failed -> past due with a 3-day grace, cancel at
 *         period end, ended, refunded). A bank-transfer method gives instructions
 *         with a reference and starts the membership when an admin marks it paid.
 *         Membership -> Payments lists every payment with Mark paid and Refund; the
 *         member account shows payment history and a link to update the card.
 *         Receipt and payment-failed emails. Extendable through the
 *         unysonplus_membership_gateways filter. Paid memberships no longer start
 *         when the email address is confirmed.
 *
 * 1.0.5 - Profile fields, the REST API and AI Assistant tools. Profile fields
 *         (Membership -> Profile fields): text, longer text, choice, tick box, date,
 *         number, phone or web address, each for every plan or only some, required or
 *         not, editable by members or not; members fill them on their account page,
 *         sign-up forms fill them by matching labels, admins edit them on the member's
 *         page, and they are in the privacy export / erase. REST namespace
 *         fw-membership/v1: public /plans and /access?post=, the member's own /me, and
 *         for managers /members, /members/{id}, POST /memberships and
 *         /memberships/{id}/{extend|cancel|expire|reactivate|activate|change-plan}, and
 *         /rules, with real HTTP status codes. AI Assistant abilities to describe the
 *         set-up and save plans, access rules and settings; none reads a member.
 *
 * 1.0.4 - Accounts and the member area. A "Register a member" form action (Settings
 *         -> Actions on any contact form): creates the WordPress account, gives the
 *         chosen plan, and can ask for email confirmation (a single-use, expiring
 *         link whose GET only shows a confirm button) and/or approval (Approve on the
 *         member's page); a refused sign-up (email already registered, plan full)
 *         shows the visitor a message and nothing is sent or stored. Username and
 *         Password form fields (the password is a secret field: never stored in
 *         Entries, emailed or echoed back). Member Login and Member Account elements
 *         (details, memberships with cancel, password, sign out other devices), login
 *         through WordPress's own login so Security's throttling and 2FA apply, and
 *         per-plan after-login / after-logout pages. Editable member emails (confirm,
 *         welcome, waiting for approval, new sign-up to approve, approved, cancelled,
 *         ended), Membership -> Settings (member pages, sign-ups, cancel, the theme's
 *         button classes, remove all data), and privacy export / erase.
 *
 * 1.0.3 - Membership visibility everywhere content is written. A "Membership" tab on
 *         every page-builder element, section and column (injected through
 *         fw_shortcode_get_options while the extension is active, placed before
 *         Advanced): show to everyone, members, people without a plan, members of
 *         chosen plans, everyone except them, logged-in or logged-out people, and
 *         "When hidden": nothing, or the members prompt in the element's place. The
 *         fields moved out of the Advanced tab with the same ids, so saved values keep
 *         working. The same panel on WordPress's own blocks in the block editor
 *         (block attributes fwMbrShow / fwMbrPlans / fwMbrDenied, applied on
 *         render_block; Unyson+ blocks use the element tab instead). And a
 *         [member_content] shortcode (alias [upw_member_content]) for text written
 *         outside the builder: show, plans (slugs or ids), prompt, else.
 *
 * 1.0.2 - Access rules and the access engine. A rule says what is protected (the
 *         entire site, all items of a post type, single items, pages under a page,
 *         items in a category or tag, a post type archive, or addresses such as
 *         /members/*, with "except" lines), who may see it (plans, roles, anyone
 *         logged in; a plan counts for the plans it includes) and what everyone else
 *         sees (a teaser and a prompt, a log-in redirect, another page, or "not
 *         found", optionally hidden from lists and search). Every rule covering a
 *         page must be passed. One engine (FW_Membership_Access) answers for the page,
 *         archives, search, feeds, the REST API, comments, sitemaps, attachments and
 *         the SEO extension's description and social tags, so protected text does not
 *         leak through them. Also: a Membership box in the editor (members only, open
 *         to everyone, or follow the rules), a "Membership" visibility field on every
 *         page-builder element and every menu item, a Paywall Prompt element, "View
 *         as" in the admin bar to preview the site as a visitor or a member of any plan,
 *         a WordPress role per plan applied on the fly (roles that can manage the site
 *         are never offered), and no-cache headers plus a plans cookie so page caches
 *         never serve one person's view to another. Filters:
 *         unysonplus_membership_rule_passes, unysonplus_membership_denied_html.
 *
 * 1.0.0 - Initial release. The Membership app: its own sidebar and top bar inside
 *         wp-admin (the WordPress menu folds to icons on its screens, and can be
 *         hidden altogether), with Home, Members, Plans and Modules working and the
 *         rest of the planned areas marked "Soon". Its own tables from the first
 *         release (plans, prices, memberships, the membership history, access
 *         rules, rule targets and payments). Plans with prices, a duration,
 *         inheritance and a priority; membership records with a status, dates and
 *         the source that granted them; members granted, extended, cancelled,
 *         expired and reactivated by hand, each change recorded in the history and
 *         fired as one documented action (see FW_Membership_Events). An hourly job
 *         expires memberships whose end date has passed.
 */

$manifest['version']    = '1.0.24';
$manifest['display']    = true;
$manifest['standalone'] = true;
$manifest['thumbnail']  = 'thumbnail.svg';

// No hard requirements on OTHER EXTENSIONS: the CRM, Forms and WooCommerce
// connections are modules that switch on only when those extensions are active.
// The framework itself is a different matter — these screens render inside its
// app shell.
$manifest['requirements'] = array(
	'framework' => array(
		'min_version' => '3.0.49', // class FW_Admin_App — the admin app shell.
	),
);

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Membership-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-Membership-Extension';
$manifest['github_branch'] = 'master';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';
