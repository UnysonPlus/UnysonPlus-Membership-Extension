# Membership — working rules

Plans, membership records, the access engine (phase 1, in progress) and switchable modules.
Read this before editing anything in this folder. The design and the roadmap are public:
https://docs.unysonplus.com/membership and /membership/roadmap (the roadmap's `detect` paths are
this folder's agreed file layout — put new files where the roadmap says).

## The layering is the design (same as the CRM)

```
Installer   includes/class-fw-membership-installer.php      the only DDL
Repository  includes/class-fw-membership-plans.php            the only SQL, no hooks
            includes/class-fw-membership-memberships.php
Service     includes/class-fw-membership-service.php          the only status changes + events
Events      includes/class-fw-membership-events.php           the catalogue of actions
Modules     includes/class-fw-membership-modules.php          switches; modules/<slug>/<slug>.php
Admin       includes/admin/*                                  the app shell + screens
```

- **Nothing above the repositories writes SQL. Nothing below the service fires a hook.**
- **Every status change goes through `FW_Membership_Service`** (grant, extend, cancel, expire,
  reactivate, change_plan). It appends to `membership_events` (append-only, never updated) and then
  fires `unysonplus_membership_{event}` + `unysonplus_membership_changed`. Gateways, the CRM module and
  REST call the service; they never write a status themselves.

## Schema discipline

The CRM installer's rules, plus three of our own learned from other membership plugins:
- `dbDelta` formatting (two spaces after PRIMARY KEY, `KEY` not `INDEX`, one field per line).
- **No ENUM columns, no `0000-00-00` defaults (NULL = not set), no foreign keys to `wp_users`.**
- Bump `FW_Membership_Installer::DB_VERSION` on any schema change; destructive steps go in `migrate()`.
- Tables never drop on deactivation; only the explicit "Remove all data" action calls `uninstall()`.
- Members are **WordPress users** — never a parallel user table or login cookie.
- **Prices are retired, not edited**: a changed price becomes a new row and the old one is archived,
  so a membership's `price_id` keeps meaning what the member agreed to.
- A plan with memberships is **archived, not deleted**.

## The app shell (keep in step with the CRM app)

`includes/admin/class-fw-membership-app.php`, `static/css/app.css` and `static/js/app.js` are a
deliberate copy of the CRM app shell (`crm/includes/admin/class-fw-crm-app.php` and its static files)
with `fw-crm-` → `fw-mbr-`. The user asked for the Membership menu to work exactly like the CRM's.
When the CRM shell changes (sidebar, top bar, focus mode, responsive), port the change here. The
Membership-only styles sit at the END of `app.css`, below the marked divider — add new ones there.
(A shared framework-level app shell is a candidate refactor; agree it with the CRM owner first.)

## The CRM connection

Membership never requires the CRM and **never touches `fw_crm_*` tables or files in `crm/`**. It
talks to the CRM only through its public API (`FW_CRM_Contacts::find_by_user / id_for_user /
link_user`, `FW_CRM_Subscribers::has_lists / add_to_list / remove_from_list`, `FW_CRM_Lists::get_or_create`
with `managed_by => 'membership'`, `FW_CRM_Activity::log`, `FW_CRM_Fields`, the
`unysonplus_crm_contact_panels` / `_activity_types` / `_dashboard_cards` / `_nav` filters). All of it
lives in `modules/crm/`, loaded only while the CRM is active. Use `add_to_list` / `remove_from_list`
for the tag mirror, never `set_lists` (it replaces the contact's whole tag set). Email sync is
user → contact only. Requests for new CRM API go to the CRM owner, not into CRM files.

## Dates

Stored as local site time (`current_time( 'mysql' )`), compared with `current_time( 'timestamp' )`,
the same as the CRM. NULL `ends_at` means "never ends".
