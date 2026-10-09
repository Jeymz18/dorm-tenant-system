<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
/**
 * includes/paymongo.php
 * Thin wrapper around the PayMongo v2 Checkout Sessions API, scoped
 * to exactly what this app needs: create a checkout for ONE payment
 * option the tenant picked (GCash, PayMaya or GoTyme) and verify the
 * webhook that confirms payment. Not a general-purpose SDK.
 *
 * Docs: https://developers.paymongo.com/docs/checkout-api
 */

/**
 * Converts a PH mobile number typed in any common local shape —
 * "0917 123 4567", "0917-123-4567", "639171234567", "9171234567" —
 * into the +639XXXXXXXXX (E.164) format PayMongo requires. Returns ''
 * if there's nothing usable, so callers can just omit the field.
 */
function ph_mobile_e164(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if ($digits === '') {
        return '';
    }

    // 09XXXXXXXXX — the usual local format.
    if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
        return '+63' . substr($digits, 1);
    }

    // 639XXXXXXXXX — country code already there, just missing the "+".
    if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
        return '+' . $digits;
    }

    // 9XXXXXXXXX — no leading 0 and no country code.
    if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
        return '+63' . $digits;
    }

    // Anything else: pass it through best-effort rather than throw,
    // so a weird number doesn't block the whole checkout.
    return str_starts_with($phone, '+') ? '+' . $digits : $digits;
}

/**
 * Low-level authenticated request to the PayMongo API.
 * Throws RuntimeException with a user-facing message on failure.
 */
function paymongo_request(string $method, string $path, ?array $body = null): array
{
    // A full URL is passed through untouched — PayMongo doesn't expose
    // every route on the same API version (see
    // paymongo_get_checkout_session). A leading "/..." is still treated
    // as relative to PAYMONGO_API_BASE, so existing callers are
    // unaffected.
    $url = str_starts_with($path, 'http') ? $path : PAYMONGO_API_BASE . $path;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        // PayMongo auth: secret key as HTTP Basic username, empty password.
        CURLOPT_USERPWD        => PAYMONGO_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Could not reach PayMongo: ' . $err);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true) ?? [];

    if ($status >= 400) {
        $message = $decoded['errors'][0]['detail'] ?? 'PayMongo request failed (HTTP ' . $status . ').';
        throw new RuntimeException($message);
    }

    return $decoded;
}

/**
 * The online payment options a tenant can pick from.
 *
 * key      => what the form posts
 * label    => shown to people AND stored in payments.payment_method, so
 *             the admin ledger and payment history read the same way
 * type     => the PayMongo `payment_method_types` value for that option
 * icon     => Bootstrap Icons class
 * hint     => one line telling the tenant what will happen next
 *
 * GoTyme has no method type of its own in PayMongo. GoTyme Bank is one
 * of the banks PayMongo lists under QR Ph, the national QR standard, so
 * the GoTyme option opens a QR Ph checkout that the tenant scans with
 * the GoTyme app.
 */
function paymongo_online_methods(): array
{
    return [
        'gcash' => [
            'label' => 'GCash',
            'type'  => 'gcash',
            'icon'  => 'bi-phone',
            'hint'  => 'You\'ll be sent to GCash to approve the payment.',
        ],
        'paymaya' => [
            'label' => 'Maya',
            'type'  => 'paymaya',
            'icon'  => 'bi-wallet2',
            'hint'  => 'You\'ll be sent to Maya to approve the payment.',
        ],
        'gotyme' => [
            'label' => 'GoTyme',
            'type'  => 'qrph',
            'icon'  => 'bi-qr-code',
            'hint'  => 'A QR code will be shown — scan it with the GoTyme app to pay.',
        ],
    ];
}

/**
 * Only the options switched on in config/paymongo.php
 * (PAYMONGO_ENABLED_METHODS). Every PayMongo account has its own list
 * of activated methods, so a method your account doesn't have yet can
 * be hidden there instead of failing at checkout. If the constant is
 * missing (an older config file) all options are offered.
 */
function paymongo_enabled_methods(): array
{
    $all = paymongo_online_methods();
    if (!defined('PAYMONGO_ENABLED_METHODS') || !is_array(PAYMONGO_ENABLED_METHODS)) {
        return $all;
    }
    return array_intersect_key($all, array_flip(PAYMONGO_ENABLED_METHODS));
}

/**
 * Find an option by the label stored in payments.payment_method
 * ("GCash", "Maya", "GoTyme"), or null for Cash / Bank Transfer /
 * anything that isn't an online option.
 */
function paymongo_method_by_label(?string $label): ?array
{
    foreach (paymongo_online_methods() as $method) {
        if ($method['label'] === $label) {
            return $method;
        }
    }
    return null;
}

/**
 * Create a Checkout Session for one payment, limited to the given
 * PayMongo method type(s) so the tenant lands on exactly the option
 * they chose.
 *
 * $amount is in pesos (e.g. 5500.00) — this converts to centavos,
 * which is what PayMongo's API expects.
 * $metadata should include something that lets the webhook find the
 * right row again, e.g. ['payment_id' => 42].
 * $billing is the person actually paying (['name', 'email', 'phone']).
 * Send it: it pre-fills the checkout page and is what identifies the
 * payer in the PayMongo dashboard. Leave it out and the field starts
 * blank, so whoever's browser is being used autofills someone else's
 * details and every tenant's payment looks like it came from them.
 *
 * Returns ['id' => 'cs_xxx', 'checkout_url' => 'https://...'].
 */
function paymongo_create_checkout(
    float $amount,
    string $description,
    string $referenceNumber,
    array $metadata,
    string $successUrl,
    string $cancelUrl,
    array $billing = [],
    array $methodTypes = ['gcash']
): array {
    $centavos = (int) round($amount * 100);

    $payload = [
        'data' => [
            'attributes' => [
                'line_items' => [[
                    'name'     => $description,
                    'amount'   => $centavos,
                    'currency' => 'PHP',
                    'quantity' => 1,
                ]],
                'payment_method_types' => array_values($methodTypes),
                'reference_number'     => $referenceNumber,
                'send_email_receipt'   => false,
                'success_url'          => $successUrl,
                'cancel_url'           => $cancelUrl,
                'metadata'             => $metadata,
            ],
        ],
    ];

    // Blanks are dropped rather than sent as null — PayMongo rejects an
    // empty string where it expects a phone number or address.
    $billing = array_filter($billing, static fn ($value) => $value !== null && $value !== '');
    if ($billing) {
        $payload['data']['attributes']['billing'] = $billing;
    }

    $response = paymongo_request('POST', '/checkout_sessions', $payload);

    return [
        'id'           => $response['data']['id'] ?? null,
        'checkout_url' => $response['data']['attributes']['checkout_url'] ?? null,
    ];
}

/**
 * Kept so anything still calling the old name keeps working.
 * New code should call paymongo_create_checkout().
 */
function paymongo_create_gcash_checkout(
    float $amount,
    string $description,
    string $referenceNumber,
    array $metadata,
    string $successUrl,
    string $cancelUrl,
    array $billing = []
): array {
    return paymongo_create_checkout($amount, $description, $referenceNumber, $metadata, $successUrl, $cancelUrl, $billing, ['gcash']);
}

/**
 * Verify the `Paymongo-Signature` header on an incoming webhook
 * request. Must be run against the RAW request body — never the
 * json_decode()'d version — or every signature will fail to match.
 *
 * Header format: t=<timestamp>,te=<test-mode-sig>,li=<live-mode-sig>
 * We accept a match against either te or li, since this app runs the
 * same webhook URL for both test and live mode.
 *
 * https://developers.paymongo.com/docs/securing-webhook
 */
function paymongo_verify_webhook_signature(string $rawPayload, string $signatureHeader, string $secret): bool
{
    $parts = [];
    foreach (explode(',', $signatureHeader) as $chunk) {
        [$key, $value] = array_pad(explode('=', trim($chunk), 2), 2, null);
        if ($key !== null && $value !== null) {
            $parts[$key] = $value;
        }
    }

    if (empty($parts['t']) || (empty($parts['te']) && empty($parts['li']))) {
        return false;
    }

    $expected = hash_hmac('sha256', $parts['t'] . '.' . $rawPayload, $secret);

    foreach (['te', 'li'] as $key) {
        if (!empty($parts[$key]) && hash_equals($expected, $parts[$key])) {
            return true;
        }
    }

    return false;
}

/**
 * Build a v1 URL for a Checkout Session route.
 *
 * Sessions are CREATED on v2 but read back and expired on v1 — v2 has
 * no route for either and answers "The requested route does not exist".
 * The version segment is swapped rather than hardcoding a second base
 * URL, so PAYMONGO_API_BASE stays the single place a host change has to
 * be made.
 */
function paymongo_v1_url(string $path): string
{
    return preg_replace('#/v\d+$#', '/v1', PAYMONGO_API_BASE) . $path;
}

/**
 * Look one Checkout Session up again by id.
 *
 * The webhook is still the source of truth for confirming payments,
 * but it can only fire at a publicly reachable URL — on a plain XAMPP
 * localhost, PayMongo simply can't call back. Re-reading the session
 * lets the tenant's own page confirm the payment itself, so the portal
 * flips to "Paid" on its own either way.
 */
function paymongo_get_checkout_session(string $checkoutId): array
{
    // Checkout Sessions are CREATED on v2 but READ BACK on v1 — v2 has
    // no GET route for them and answers "The requested route does not
    // exist". The version segment is swapped rather than hardcoding a
    // second base URL, so PAYMONGO_API_BASE stays the single place a
    // host change has to be made.
    $readBase = preg_replace('#/v\d+$#', '/v1', PAYMONGO_API_BASE);

    return paymongo_request('GET', $readBase . '/checkout_sessions/' . rawurlencode($checkoutId));
}

/**
 * Expire a Checkout Session so nobody can pay it any more.
 *
 * Run this before discarding an abandoned payment row: a checkout link
 * the tenant left open in another tab could otherwise still be paid
 * after the portal had stopped tracking it, and that money would never
 * show up against their rent. Returns false if PayMongo refused (an
 * already-paid session can't be expired) — the caller must then keep
 * the row rather than risk losing a real payment.
 */
function paymongo_expire_checkout_session(string $checkoutId): bool
{
    try {
        paymongo_request('POST', paymongo_v1_url('/checkout_sessions/' . rawurlencode($checkoutId) . '/expire'));
        return true;
    } catch (Throwable $e) {
        // A session that's already expired refuses a second expiry —
        // but that's the state we were asking for, so check before
        // reporting failure and stranding the row.
        try {
            $session = paymongo_get_checkout_session($checkoutId);
            if (($session['data']['attributes']['status'] ?? null) === 'expired') {
                return true;
            }
        } catch (Throwable $ignored) {
        }

        error_log('GCash: could not expire checkout ' . $checkoutId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Boil a Checkout Session response down to what this app cares about.
 *
 * Returns ['status' => ..., 'payment_id' => ?string], where status is:
 *   paid      — money arrived, settle the row
 *   failed    — a real attempt was made and declined
 *   abandoned — the tenant never even picked a payment method, so
 *               nothing happened and nothing is outstanding; the row
 *               can be thrown away rather than shown as a debt
 *   unpaid    — an attempt is in flight, or PayMongo described this in
 *               a way we don't recognise. The safe answer: leave the
 *               payment Pending and look again later.
 */
function paymongo_checkout_payment_status(array $session): array
{
    $attributes = $session['data']['attributes'] ?? [];

    // A settled session lists the payment in two places. Check both —
    // they agree in practice, but relying on only one would make this
    // brittle if PayMongo trims either from the payload.
    $payments = array_merge(
        $attributes['payment_intent']['attributes']['payments'] ?? [],
        $attributes['payments'] ?? []
    );

    $declined = false;
    $inFlight = false;

    foreach ($payments as $payment) {
        $status = $payment['attributes']['status'] ?? null;
        if ($status === 'paid') {
            return ['status' => 'paid', 'payment_id' => $payment['id'] ?? null];
        }
        if ($status === 'failed') {
            $declined = true;
        } else {
            $inFlight = true;
        }
    }

    // No paid payment listed. The session's own status and its
    // payment_intent together say whether anything is still outstanding.
    $intentStatus  = $attributes['payment_intent']['attributes']['status'] ?? null;
    $sessionStatus = $attributes['status'] ?? null;

    if ($intentStatus === 'succeeded' || $sessionStatus === 'paid') {
        return ['status' => 'paid', 'payment_id' => $attributes['payment_intent']['id'] ?? null];
    }
    if ($inFlight) {
        return ['status' => 'unpaid', 'payment_id' => null];
    }
    if ($declined || in_array($intentStatus, ['cancelled', 'canceled'], true)) {
        return ['status' => 'failed', 'payment_id' => null];
    }

    // Nothing was ever attempted here. PayMongo only attaches a
    // payment_intent once the customer picks a method, so a null one on
    // a live session means they opened the link and walked away; an
    // expired session can't be paid any more either way. A declined
    // attempt also leaves the intent at awaiting_payment_method, which
    // is why the $declined check above has to run first.
    if ($sessionStatus === 'expired'
        || $intentStatus === 'awaiting_payment_method'
        || ($sessionStatus === 'active' && ($attributes['payment_intent'] ?? null) === null)) {
        return ['status' => 'abandoned', 'payment_id' => null];
    }

    return ['status' => 'unpaid', 'payment_id' => null];
}
