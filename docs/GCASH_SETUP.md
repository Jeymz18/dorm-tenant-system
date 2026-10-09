# GCash Payments Setup (via PayMongo)

This app now takes real GCash payments through **PayMongo's Hosted
Checkout**: a tenant clicks "Pay with GCash", is redirected to a
PayMongo-hosted page to authorize the payment in GCash, and is
brought back to your site. PayMongo then calls a **webhook** on your
server to confirm the payment — that webhook is the only thing that
ever marks a payment `Paid`. The old manual "type in a reference
number + upload a screenshot" flow has been removed for GCash.

## 1. About the TIN / ID requirement

You mentioned you don't have a TIN or ID on your PayMongo account yet.
Here's what that actually blocks, and what it doesn't:

- **Test mode (`sk_test_...` / `pk_test_...`) does not require a TIN
  or full business verification (KYB).** It does need basic sign-up +
  identity verification (email/SMS + a government ID + a liveness
  check) — that part isn't skippable. Once that's done, test mode
  works immediately and moves no real money, so you can build and
  fully test this entire flow today with dummy GCash test payments.
- **Live mode (`sk_live_...` / `pk_live_...`)** — actually receiving
  real pesos into your account — does require business verification
  (KYB), which asks for a TIN and business documents for most account
  types. That's a PayMongo/BSP compliance requirement, not something
  this code can work around.

So: finish basic KYC (ID + liveness), build and test everything
against test keys, and swap in live keys later once KYB clears. Set the API keys and public URL locally through environment variables; never commit real keys.

## 2. Get your keys

PayMongo Dashboard → **Developers → API Keys**. Set these environment variables for the PHP/Apache process (or set them in a private local-only configuration that is not committed):

- `PAYMONGO_SECRET_KEY=sk_test_...`
- `PAYMONGO_PUBLIC_KEY=pk_test_...`

The app intentionally disables checkout when either key is missing or has the wrong prefix. Never paste live keys into a public GitHub repository.

## 3. Run the database migration

```
mysql -u root -p dorm_tenant_system < database/migration_add_paymongo_columns.sql
```

(Fresh installs get these columns automatically from `database/schema.sql`. For an existing database, run `database/migration_add_reset_otp.sql` if the OTP columns are missing, then run `database/migration_secure_reset_otp.sql` to enable hashed OTP storage and the five-attempt limit.)

## 4. Set your public URL

`config/app.php` reads `APP_URL` from the environment (falling back to localhost) — the full `https://...` URL
of your site. PayMongo needs this for two things: sending the tenant
back to your site after paying, and calling your webhook. Neither
works against `localhost`.

**For local testing**, use a tunnel like [ngrok](https://ngrok.com):

```
ngrok http 80        # or whatever port your local server uses
```

ngrok prints an `https://xxxx.ngrok-free.app` URL — put that in
`APP_URL`:

Set `APP_URL=https://xxxx.ngrok-free.app/dorm-tenant-system` in the PHP/Apache environment. Keep the path matching `BASE_URL`.

**In production**, use your real domain instead.

## 5. Register the webhook

PayMongo Dashboard → **Developers → Webhooks → Add endpoint**.

- URL: `{APP_URL}/webhooks/paymongo.php`
  (e.g. `https://xxxx.ngrok-free.app/dorm-tenant-system/webhooks/paymongo.php`)
- Events to send: `checkout_session.payment.paid` and
  `checkout_session.payment.failed`

PayMongo shows a **webhook signing secret** (`whsk_...`) once, when
you create the endpoint. Set `PAYMONGO_WEBHOOK_SECRET=whsk_...` in the PHP/Apache environment. Keep this value private.

Every incoming webhook request is checked against this secret before
anything in it is trusted (`includes/paymongo.php` →
`paymongo_verify_webhook_signature()`), so a stranger who finds your
webhook URL can't just POST a fake "paid" event and mark a rent
payment as settled.

## 6. Test it end to end

1. Log in as a tenant with an active contract → **Payments** → enter
   a month → **Pay with GCash**.
2. You'll land on PayMongo's checkout page. In test mode, PayMongo
   provides test GCash credentials/OTP for a simulated successful (or
   failed) payment — check PayMongo's test payment method docs for
   the current test flow, since they occasionally change it.
3. On success, you're redirected back to `/tenant/payments.php`.
   The row shows **Pending** for a moment ("Awaiting GCash
   confirmation") until the webhook lands — usually a few seconds —
   then flips to **Paid** automatically. No admin action needed.
4. If ngrok isn't running or the webhook URL is wrong, the payment
   will stay stuck on Pending forever. Check ngrok's request log
   (`http://127.0.0.1:4040`) to see if PayMongo even reached your
   server, and check your PHP error log for anything
   `webhooks/paymongo.php` threw.

## 7. What still needs a human

- **Cash / Bank Transfer / other methods**: admins still record these
  manually in `admin/payments.php` → "Record Payment" — those
  payments genuinely happen outside any API, so there's nothing to
  automate.
- **Refunds / disputes**: not built here. Handle those from the
  PayMongo Dashboard directly for now.
- **Reconciliation fallback**: if a webhook is ever missed (server
  downtime, etc.), `admin/payments.php` still has a manual "Mark
  Paid" button as a safety net for a `Pending`/GCash row — check the
  PayMongo Dashboard for the actual payment status before using it.
