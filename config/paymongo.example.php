<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
/**
 * config/paymongo.php
 * Credentials for the PayMongo GCash integration.
 *
 * Where to get these: PayMongo Dashboard -> Developers -> API Keys.
 *
 *  - TEST keys (sk_test_..., pk_test_...) work as soon as you finish
 *    basic sign-up + KYC (email + government ID + liveness check).
 *    You do NOT need a TIN or full business verification (KYB) to
 *    build and test this whole flow — test mode never moves real
 *    money, so build against sk_test_/pk_test_ today.
 *
 *  - LIVE keys (sk_live_..., pk_live_...) only work once your account
 *    is "Activated" for real payments, which does require KYB
 *    (business info + TIN) for most business types. Swap the values
 *    below to your live keys + live webhook secret when that's done —
 *    nothing else in the code needs to change.
 *
 * Never commit real keys to a public repo. If this project is on
 * GitHub, add config/paymongo.php to .gitignore and commit
 * config/paymongo.example.php instead.
 */

define('PAYMONGO_API_BASE', 'https://api.paymongo.com/v2');

define('PAYMONGO_WEBHOOK_SECRET', ''); // Set locally or through environment variables.

/** True once real test/live keys have been filled in above. */
function paymongo_configured(): bool
{
    return PAYMONGO_SECRET_KEY !== '' && PAYMONGO_PUBLIC_KEY !== ''
        && str_starts_with(PAYMONGO_SECRET_KEY, 'sk_')
        && str_starts_with(PAYMONGO_PUBLIC_KEY, 'pk_');
}
