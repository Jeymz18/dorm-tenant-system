# Dorm Tenant Management System — Audit Fix Report

Date: 2026-10-09

## Changes made

1. `auth/reset_password.php`
   - Corrected the OTP expiry message from 15 minutes to 10 minutes.
   - Clears expired OTP state when an expired code is submitted.
   - Changed incorrect-attempt accounting to an atomic database increment guarded by the current OTP, expiry, and attempt limit, reducing the chance that concurrent requests bypass the five-attempt limit.
   - Requires the OTP to remain unexpired and under the attempt limit at the password update operation.

2. `auth/resend_otp.php`
   - Resend requests must target the email bound to the current password-reset session; callers cannot switch the session to a different account by posting another email.
   - Normalized the email lookup and removed email addresses from one OTP-related database error log.

3. `includes/functions.php`
   - `absolute_url()` now uses the configured `APP_URL` rather than the untrusted request `Host` header, preventing Host-header poisoning of generated links.
   - Upload helper now validates the upload destination against known directories, verifies the temporary file is an HTTP upload, handles missing upload fields safely, and uses cryptographically random filenames.

4. `tenant/payments.php`
   - Payment initiation now locks the tenant row and re-checks for an existing Paid/Pending payment inside the transaction before inserting a new Pending row. This reduces duplicate payment races caused by double submissions.

5. `admin/payments.php`
   - Manual payment recording locks the tenant row before checking for a duplicate Paid/Pending billing-month payment, serializing simultaneous admin submissions for that tenant.

6. `tenant/profile.php`
   - Profile password changes now use the same password policy as password reset.
   - Updating a password updates `password_changed_at` and refreshes the current session snapshot, so other sessions can be invalidated without unexpectedly logging out the session that performed the change.

7. `.gitignore` and `config/mail.local.php`
   - Added ignore rules for local mail credentials and environment files. The original ZIP still contained `config/mail.local.php` despite the ignore rule, so the file has now been removed from the deliverable. SMTP can be configured locally from `config/mail.local.example.php`. Because the uploaded archive contained an actual-looking Gmail app password, revoke it and create a new app password immediately if it was real.

8. `cron/check_expirations.php`
   - Removed the assumption that the system admin always has `user_id = 1`. The job now selects an active admin/super-admin account and exits with an explicit error if none exists, preventing silent notification failures on imported or long-lived databases.

## Checks performed

- PHP syntax lint: all application PHP files (excluding third-party `vendor/`) passed `php -l`.
- JavaScript syntax: all 7 JavaScript files passed `node --check`.
- Database integration tests were not run because no live database connection was available in this environment. No live database connection was available to validate migrations, SQL execution, constraints, or real transaction locking.
- PayMongo end-to-end tests were not run because they require configured credentials and a reachable webhook endpoint.
- Email delivery, camera capture, browser image previews, and all dashboard buttons/forms require testing in the deployed browser/application environment.

## Remaining work / limitations

This is a targeted source-code hardening pass, not proof that every feature in the original audit checklist is correct. The following still require runtime testing against a copy of the real database and a browser:

- PayMongo success/failure/cancel/delayed webhook workflows, signature verification, and consistency among payment, billing, and history views.
- Existing duplicate payment cleanup and a safe production migration strategy for any database uniqueness constraints.
- Room assignment, reservations, contract renewal/termination/expiration, and move-out state synchronization under concurrent use.
- Admin and tenant dashboard controls, modals, search, filters, pagination, camera capture, and file upload workflows.
- Authorization/CSRF review across every state-changing route and the actual database schema versus migrations.

Do not apply database migrations to production without a backup and testing against a staging copy first.
