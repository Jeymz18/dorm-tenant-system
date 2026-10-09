<?php
/**
 * webhooks/paymongo.php
 *
 * PayMongo calls this URL server-to-server when a checkout (GCash,
 * PayMaya or GoTyme/QR Ph) is paid (or fails). This is intentionally OUTSIDE config/ and
 * includes/ (which are locked down by .htaccess) because PayMongo's
 * servers need to reach it directly, with no login/session/CSRF —
 * none of that applies to a server-to-server callback.
 *
 * Register this URL in PayMongo Dashboard -> Developers -> Webhooks:
 *   https://yourdomain.com/dorm-tenant-system/webhooks/paymongo.php
 * Subscribe it to: checkout_session.payment.paid,
 *                   checkout_session.payment.failed
 *
 * Security: every request is checked against the Paymongo-Signature
 * header before anything in the payload is trusted. Never skip this —
 * anyone who finds this URL could otherwise mark any payment Paid
 * just by POSTing a fake JSON body.
 */

require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json');

$rawPayload = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

if ($rawPayload === '' || !paymongo_verify_webhook_signature($rawPayload, $signatureHeader, PAYMONGO_WEBHOOK_SECRET)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($rawPayload, true);
$type  = $event['data']['type'] ?? null;
$db    = get_db();

if ($type === 'checkout_session.payment.paid') {
    $session       = $event['data']['data'] ?? [];
    $checkoutId    = $session['id'] ?? null;
    $paymentIntent = $session['attributes']['payment_intent'] ?? [];
    $paidPayment   = $paymentIntent['attributes']['payments'][0] ?? null;
    $paymongoPaymentId = $paidPayment['id'] ?? ($paymentIntent['id'] ?? null);

    if ($checkoutId) {
        // Guard with payment_status != 'Paid' so a duplicate/retried
        // webhook delivery (PayMongo may send the same event more
        // than once) can't double-process a payment.
        $updated = $db->prepare("UPDATE payments
                SET payment_status = 'Paid',
                    payment_date = CURDATE(),
                    paid_at = NOW(),
                    paymongo_payment_id = ?,
                    webhook_received_at = NOW()
                WHERE paymongo_checkout_id = ? AND payment_status != 'Paid'");
        $updated->execute([$paymongoPaymentId, $checkoutId]);
        if ($updated->rowCount() > 0) {
            $payment = $db->prepare('SELECT payment_id FROM payments WHERE paymongo_checkout_id = ?');
            $payment->execute([$checkoutId]);
            $paymentId = (int) $payment->fetchColumn();
            log_action('payment_confirmed', 'PayMongo confirmed payment #' . $paymentId);
            send_payment_invoice($db, $paymentId);
        }
    }
} elseif ($type === 'checkout_session.payment.failed') {
    $session    = $event['data']['data'] ?? [];
    $checkoutId = $session['id'] ?? null;

    if ($checkoutId) {
        $db->prepare("UPDATE payments
                SET payment_status = 'Failed', webhook_received_at = NOW()
                WHERE paymongo_checkout_id = ? AND payment_status = 'Pending'")
           ->execute([$checkoutId]);
    }
}

// Always 200 on anything we understood (or intentionally ignored) —
// a non-2xx response makes PayMongo retry the same event repeatedly.
http_response_code(200);
echo json_encode(['received' => true]);
