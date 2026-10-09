# Dorm Tenant Management System

A role-based dorm/property management system: PHP (procedural, PDO) +
MySQL + Bootstrap 5. Built out from an existing functional
decomposition diagram, system flowchart, DFDs, data dictionary, and
UI prototype into a working local app for XAMPP.

## Tech stack

- **Backend:** PHP 8+, PDO with prepared statements everywhere
- **Database:** MySQL / MariaDB (via phpMyAdmin or CLI)
- **Frontend:** HTML5, CSS3, Bootstrap 5 (CDN), vanilla JavaScript
- **Charts:** Chart.js (CDN, admin dashboard only)

No framework, no Composer requirement for the core app — it runs
by just copying the folder into `htdocs`. Composer is only needed if
you turn on real email sending (see below).

## 1. Setup (XAMPP)

1. **Copy the project** into your XAMPP `htdocs` folder, so you end up
   with `C:\xampp\htdocs\dorm-tenant-system\` (or
   `/Applications/XAMPP/xamppfiles/htdocs/dorm-tenant-system` on macOS).
2. **Start Apache and MySQL** from the XAMPP control panel.
3. **Import the database:** open phpMyAdmin
   (`http://localhost/phpmyadmin`) → **Import** → choose
   `database/schema.sql` → Go. This creates the `dorm_tenant_system`
   database, all 8 tables, and demo data.
   - Command-line alternative: `mysql -u root -p < database/schema.sql`
4. **Check `config/database.php`** — the defaults (`root` / no
   password / `localhost`) match a fresh XAMPP install. Change them if
   yours is different.
5. **Check `BASE_URL`** in `config/app.php` — it's set to
   `/dorm-tenant-system` to match this folder name. If you rename the
   folder, update this constant to match.
6. Visit **`http://localhost/dorm-tenant-system/`** — you should land
   on the login page.

### Demo credentials

| Role   | Email                       | Password    |
|--------|------------------------------|-------------|
| Admin  | `admin@dorm.edu`             | `Admin@123` |
| Tenant | `angel@student.dorm.edu`     | `Tenant@123`|

Both are also one-click buttons on the login page ("Quick Login (Demo)").
**Change the admin password** (via User Management → Edit) before you
show this to anyone else.

### Already set this up before? Run the migrations

Two columns were added to `tenants` after some people had already
imported `schema.sql`. If a page throws `Unknown column`, run
whichever of these you're missing — same method both times
(phpMyAdmin's SQL tab, or `mysql -u root -p dorm_tenant_system < database/<file>`),
and neither touches your existing data:

| Missing column | Breaks | Run this |
|---|---|---|
| `key_returned` | Check-in/Check-out Monitor | `database/migration_add_key_returned.sql` |
| `rejection_reason` | Tenant Registration/Approval (rejecting or reconsidering an applicant) | `database/migration_add_rejection_reason.sql` |
| `reset_otp`, `reset_otp_expires` | Forgot Password | `database/migration_add_reset_otp.sql` |
| `password_changed_at` | Force-logout other sessions after a password reset | `database/migration_add_password_changed_at.sql` |
| `dismissed_records` table | "Clear" on Registration/Approval, Track Status, Check-in/Check-out | `database/migration_add_dismissed_records.sql` |
| `paymongo_checkout_id`, `paymongo_payment_id`, `webhook_received_at` | Paying rent with GCash | `database/migration_add_paymongo_columns.sql` |
| `renewal_requested_at`, `last_renewed_at`, `renewal_count`, `terminated_at`, `termination_reason` | Renew / Terminate Contract | `database/migration_add_contract_renewal.sql` |

Setting up fresh right now? Skip them all — `schema.sql` already
includes everything.

### Want a clean slate instead of the demo tenant?

`schema.sql` seeds one sample tenant (Angel Benitez) so the dashboard
isn't empty on first run. To remove that tenant and practice the
register → approve → assign → contract → payment flow yourself, run
`database/clear_demo_tenant.sql` the same way (phpMyAdmin's SQL tab,
or `mysql -u root -p dorm_tenant_system < database/clear_demo_tenant.sql`).
It deletes that one account and frees up Room 102 — everything else
you've added stays untouched. The login page's tenant "Quick Login"
button won't work afterward, which is expected until you create a
tenant of your own.

## 2. Folder structure

```
dorm-tenant-system/
├── index.php              # entry point → redirects to login or dashboard
├── config/
│   ├── app.php             # bootstrap: session, constants, requires everything else
│   ├── database.php        # PDO connection
│   └── paymongo.php        # PayMongo GCash credentials (gitignored — see paymongo.example.php)
├── includes/
│   ├── auth.php            # login/logout, require_role() guards
│   ├── functions.php       # sanitizing, CSRF, flash messages, file uploads, contract/rent helpers
│   ├── email.php           # PHPMailer wrapper for alerts
│   ├── paymongo.php        # PayMongo API client (checkout sessions, webhook signature check)
│   ├── report_data.php     # shared query logic for Reports & Analytics
│   ├── tenant_action_handler.php  # shared approve/checkin/checkout/evict/clear-view logic (see below)
│   ├── header.php / footer.php / sidebar.php   # shared layout
├── webhooks/paymongo.php   # PayMongo server-to-server payment confirmation (see docs/GCASH_SETUP.md)
├── auth/                   # register.php, login.php, forgot/reset password, logout.php
├── admin/                  # dashboard + 11 admin pages, one per sidebar sub-section
├── tenant/                 # 5 modules + dashboard
├── assets/css/style.css    # the maroon theme
├── assets/js/               # validation.js, main.js
├── database/schema.sql     # full schema + seed data
├── cron/check_expirations.php  # scheduled automation (see §5)
├── uploads/                # receipts / contracts / maintenance photos
└── docs/                   # diagrams.md (ER + flowcharts), GCASH_SETUP.md
```

The admin sidebar's sub-items each route to their own page, one-for-one
with the approved navigation — with a few intentional exceptions where
the prototype's own screens are shared too (e.g. Property Management's
three sub-items — Add/Update Dorm Info, Assign Tenants, Monitor
Availability — are all the same `admin/rooms.php` screen):

| Sidebar group | Sub-item | File |
|---|---|---|
| User Management | Register Account, Identify Role | `admin/users.php` |
| | Log In Credentials | `admin/credentials.php` |
| | Manage Tenants | `admin/manage-tenants.php` |
| Property Management | all three | `admin/rooms.php` |
| Tenant Management | Tenant Registration/Approval | `admin/tenants.php` |
| | Track Status | `admin/tenant-status.php` |
| | Monitor Check-In/Check-out | `admin/checkinout.php` |
| Payment & Contract Management | all three | `admin/payments.php` |
| Maintenance Management | all three | `admin/maintenance.php` |
| Notification Management | all three | `admin/notifications.php` |
| Reports & Analytics | each links to its own section | `admin/reports.php#...` |

Because `admin/tenants.php`, `admin/tenant-status.php`, and
`admin/checkinout.php` all trigger the same five actions (approve,
reject, check-in, check-out, evict) — several of which touch multiple
tables in a transaction — that logic lives once in
`includes/tenant_action_handler.php` and all three pages `require` it
rather than each carrying their own copy. Copy-pasting transactional
code three times is exactly how those copies quietly drift apart.

Every admin/tenant page follows the same shape:

```php
require_once __DIR__ . '/../config/app.php';
require_role('admin');   // or 'tenant' — the real access-control check

// handle POST actions (CSRF-checked, prepared statements)...
// fetch data for the page...

include __DIR__ . '/../includes/header.php';
// ...HTML...
include __DIR__ . '/../includes/footer.php';
```

## 3. Design decisions worth knowing about

**Why `contracts` and `payments` are separate tables, not the merged
`PAYMENT_CONTRACT_DB` from the original data dictionary:** your own
requirement #4 says *"payments linked to contracts"* — a one-to-many
relationship — and the prototype's tenant "Payment History" screen
shows several months of payments under a single lease. A merged table
would force every payment row to repeat the contract's start/end
dates. Splitting them out is the same information, normalized; every
field name still traces back to the original dictionary.

**Why there's a `notifications` table that wasn't in the data
dictionary:** the DFD implies a "Notification Logs" store (D6) but the
dictionary never spells out its columns. It's added so Notification
Management is actually functional, not just a form that goes nowhere.

**Why `tenants` has a `key_returned` column that wasn't in the data
dictionary:** the prototype's Check-in/Check-out Monitor tracks key
return status per tenant, and there was nowhere to store that. It
defaults to `FALSE`, gets reset on check-in, and is set from a
checkbox on check-out (or after the fact from the Monitor page).

**Why `tenants` also has a `rejection_reason` column:** a rejected
application used to be a dead end — the applicant saw the same "under
review" message forever, and there was no way to reconsider a
mistaken rejection. Rejecting now optionally records why, the tenant
sees that reason instead of a misleading "check back soon", and a
"Reconsider" button on the Recently Processed list clears it and
moves the application back to Pending.

**Why `users` has `reset_otp` / `reset_otp_expires` columns:** these
back the "Forgot Password" flow — `auth/forgot_password.php` emails a
6-digit code (valid 15 minutes) to the address on file, and
`auth/reset_password.php` checks it with a timing-safe comparison
(`hash_equals()`, the same guard used for CSRF tokens) before letting
someone set a new password. Whether or not the email is actually
registered, the page shows the same "if that email is registered…"
message — otherwise the form itself becomes a way to check who has an
account. Requesting a second code within a minute of the first is a
no-op rather than sending another email, so the feature can't be used
to spam someone's inbox. **This only actually emails anything once
PHPMailer is installed** (see §4 below) — until then, like every other
alert in the app, the code is written to the PHP error log instead of
silently failing, so you can still test the flow by reading the log.

**A few deliberate hardening passes worth knowing about**, since
they're easy to miss reading the code page-by-page:
- Deactivating an account (Log In Credentials or Manage Tenants) now
  takes effect immediately, not just on their next login —
  `require_login()` re-checks `is_active` against the database on
  every request.
- A tenant can't end up with two overlapping active contracts;
  `create_contract` checks for one server-side, not just via the "who
  shows up in this dropdown" filtering in the UI.
- File uploads are checked by content, not just extension —
  `getimagesize()` for images, a `%PDF-` byte check for PDFs — on top
  of the existing extension allowlist and the `.htaccess` block on
  executing anything inside `/uploads/`.
- Log In Credentials, Manage Tenants, Track Status, the
  Check-in/Check-out Monitor, and the Payment Status list are all
  paginated (`includes/functions.php`'s `paginate()` /
  `pagination_links()`) instead of rendering every row — the schema
  ships with only a handful of rows, but a semester's worth of real
  tenants and payments would otherwise make those pages very long.

**Role-based UI separation, enforced twice:**
1. `includes/sidebar.php` only *renders* the nav links for
   `current_role()` — an admin session never even generates tenant
   HTML, and vice versa.
2. Every single page under `/admin/` and `/tenant/` calls
   `require_role('admin')` / `require_role('tenant')` as its very
   first line. That's the part that actually matters: if a tenant
   types `/admin/users.php` into the address bar, they're bounced to
   their own dashboard before any admin data is even queried. Hiding
   a button is a UI nicety; this is the real control.

**Tenant approval flow:** public self-registration always creates a
`tenant`-role account with `approval_status = 'Pending'` — there's no
way to sign up as an admin from the public form. An admin either
approves that application (Tenant Management) or creates additional
admin/staff accounts directly (User Management), which is the only
path to an `admin` role account after the seeded default.

**Payment verification flow:** when a tenant submits a payment, it's
inserted as `Pending` with their uploaded receipt attached. It only
becomes `Paid` once an admin verifies it in Payment & Contract
Management. `cron/check_expirations.php` flips unpaid, past-due
payments to `Overdue` automatically.

**Security basics included:** `password_hash()` / `password_verify()`
(bcrypt, never plaintext), PDO prepared statements throughout (no
string-concatenated SQL anywhere), CSRF tokens on every form,
`session_regenerate_id()` on login, `.htaccess` blocking script
execution inside `/uploads/`, and file-upload validation (extension +
content, not extension alone) before anything touches disk.

**Every admin page checks its own role — verified, not assumed.** All
13 files under `/admin/` open with the identical two lines,
`require_once` the bootstrap and then `require_role('admin')`, before
any query or output. A logged-in tenant hitting any admin URL directly
gets bounced to their own dashboard by `require_role()`
(`includes/auth.php`) before that page's code ever runs — confirmed by
checking the first few lines of every single admin file, not just a
sample.

**`/includes/` and `/config/` are blocked from direct web access, on
two layers.** Files there (`header.php`, `sidebar.php`,
`tenant_action_handler.php`, `database.php`, etc.) are only meant to
be `require`d by a page that already loaded `config/app.php` — several
of them use functions or constants that only exist after that
bootstrap runs. Hitting one directly wouldn't expose data (it has
nothing loaded to expose), but it would throw an uncaught PHP error
that leaks the server's file path. Both folders now have an
`.htaccess` denying all direct requests, and every file in them also
checks `defined('BASE_URL')` and exits cleanly if it's missing — so
the block holds even somewhere `.htaccess` is ignored.

**Every form field is read through `str_input()`, not raw off
`$_POST`/`$_GET`.** A normal form submission always hands PHP a
string, but nothing stops a request from sending `field[]=x` instead
— that hands over an array, and PHP 8's `trim()`, `strlen()`,
`password_verify()`, and `hash_equals()` all throw an uncaught
`TypeError` on anything but a string. That crashed the page for
*anyone*, no login required — including `auth/login.php` and
`csrf_verify()` itself, which runs on every single form in the app.
`str_input()` (`includes/functions.php`) reads the value, and quietly
falls back to a default instead of crashing if it isn't actually a
string. All 40+ call sites across the app go through it now.

**Database connection failures no longer echo the raw exception.**
`get_db()` used to `die()` with `$e->getMessage()` inlined — on a
misconfigured server that can include connection details. It now
logs the real error server-side (`error_log()`) and shows a generic
message to whoever's looking at the page.

**Why a renewal reuses the contract row instead of creating a new
one:** a lease that gets extended is still the same lease, and the
tenant's "Payment History" screen is built around that — every payment
hangs off one `contract_id`. Renewing writes a new `contract_end` onto
the existing row and bumps `renewal_count`, so nothing gets orphaned
behind a second contract. Terminating is the opposite case: it's a
hard stop, so `contract_status` goes to `Terminated` and that row can
never be renewed again — replacing it means creating a genuinely new
contract.

**Why contract statuses are recalculated on page load:** nothing used
to move a lease along on its own, so a contract that ended months ago
still displayed as "Active" everywhere. `refresh_contract_statuses()`
(in `includes/functions.php`) does that bookkeeping — past its end date
becomes `Expired`, inside 30 days becomes `Expiring Soon` — and it's
idempotent with a per-request guard, so calling it at the top of any
page that reads a contract status is free. `cron/check_expirations.php`
runs it too, so it still happens on a day nobody signs in. `Terminated`
is deliberately never touched by it: that's an admin decision, not
something a date should be able to undo.

**Why the tenant can request a renewal but not set the dates:** the
tenant portal's "Request Renewal" button only stamps
`renewal_requested_at`, which surfaces against their contract in
Payment & Contract Management. Who gets to stay, for how long, and at
what rent stays an admin decision — the tenant just gets a way to ask
that doesn't involve walking to the office.

**Why the rent status on the tenant home is derived, never stored:**
there's no "is this month paid" flag to keep in sync — and therefore
nothing that can drift out of sync. `tenant_rent_status()` reads the
`payments` table for the current billing month and decides: a `Paid`
row means green/Paid, `Pending` means amber, anything else (including
a `Failed` attempt, or no row at all) means red/Due. A `Paid` row
always outranks a stray `Pending` retry for the same month, so a
duplicate attempt can't drag a settled month back to unpaid. That's
what makes the status update on its own the moment a payment lands,
from any direction — the GCash webhook, the status check below, or an
admin recording a cash payment.

**Why the pay forms use a month dropdown instead of a text box:** that
derivation only works if "September 2026" is written the same way
every time. A free-text field produced "Sept 2026" and "sept 2026",
which no reliable matching can reconcile, so both pay forms now pick
from `billing_month_options()`. The column and its stored format are
unchanged — only the input is constrained.

**Why payments are also confirmed by polling, not just the webhook:**
`webhooks/paymongo.php` is still the primary path, but PayMongo can
only call a publicly reachable URL, and a plain XAMPP localhost isn't
one — payments would sit at `Pending` forever while testing.
`sync_pending_gcash_payments()` closes that gap by asking PayMongo
about open checkouts when the tenant loads a page. It's deliberately
bounded: GCash rows only, still `Pending`, attempted within the last
24 hours, at most 3 lookups per request, every failure swallowed and
logged. Both paths use the same `payment_status != 'Paid'` guard, so
whichever gets there first wins and the other is a no-op. Note that
Checkout Sessions are *created* on PayMongo's `/v2` but *read back* on
`/v1` — `/v2` has no GET route for them.

**What's intentionally simple, and how to level it up:**
- *PDF reports:* `admin/report_print.php` is the on-screen Review/Print
  view; `admin/report_export.php` renders the same data through
  [Dompdf](https://github.com/dompdf/dompdf) for a real one-click PDF
  download — no browser Print dialog needed.
- *Email:* works out of the box only once you install PHPMailer (see
  §4). Until then, alerts are written to PHP's error log instead of
  silently failing.
- *Maintenance teams* are a fixed list in `admin/maintenance.php`
  rather than their own table — fine for a handful of teams, but
  promote it to a `staff` table if you need scheduling per person.

## 4. Turning on real email alerts (optional)

By default, `send_email_alert()` just logs instead of sending, so
nothing breaks if you skip this section.

```bash
composer require phpmailer/phpmailer
```

No Composer? Download the `src/` folder from
[PHPMailer's GitHub](https://github.com/PHPMailer/PHPMailer) into
`vendor/phpmailer/` and adjust the `require` path in
`includes/email.php` to point at its three files directly instead of
`vendor/autoload.php`.

Then in `includes/email.php`, fill in:

```php
define('SMTP_USERNAME', 'your-app-email@gmail.com');
define('SMTP_PASSWORD', 'your-16-char-app-password'); // Gmail "App Password", not your login password
```

## 5. Automated notifications (cron)

`cron/check_expirations.php` finds contracts expiring within 7 days
and payments now past their due date, logs a notification for each,
and (if PHPMailer is set up) emails the tenant. Run it manually any
time to test:

```bash
C:\xampp\php\php.exe cron\check_expirations.php
```

To run it automatically every morning, add a **Windows Task
Scheduler** task (Program: `C:\xampp\php\php.exe`, Argument: the full
path to the script) or a cron entry on macOS/Linux — both are spelled
out at the top of the file itself.

## 6. Extending it

Every admin CRUD module follows the same recipe, so adding a new one
is mostly copy-and-adjust:

1. Add/adjust the table in `database/schema.sql`.
2. New file in `admin/` (or `tenant/`): `require_role(...)` → handle
   `$_POST` actions with `csrf_verify()` + prepared statements →
   query → `include header.php` → Bootstrap table/cards + a modal
   form → `include footer.php`.
3. Add the nav link in `includes/sidebar.php` under the right role
   block.
4. Reuse what's already there: `clean()`, `peso()`,
   `status_badge_class()`, `handle_upload()` in
   `includes/functions.php` — don't re-invent them per page.

## 7. Diagrams

See [`docs/diagrams.md`](docs/diagrams.md) for the Mermaid source of
the ER diagram, the login/role-routing flowchart, and the
payment-contract lifecycle — paste any block into
[mermaid.live](https://mermaid.live) to view it rendered.
