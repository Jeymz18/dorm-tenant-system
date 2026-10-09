<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

if (!$tenant || $tenant['approval_status'] !== 'Approved') {
    redirect('/tenant/dashboard.php');
}

refresh_contract_statuses($db);

// An expired lease still gets to settle its final month's rent — only a
// terminated one is closed to new payments.
$contract = tenant_current_contract($db, (int) $tenant['tenant_id']);
$contractExpired = contract_is_expired($contract);
$monthOptions = $contract ? contract_billing_months($contract) : [];
$paidMonths   = $contract ? tenant_paid_months($db, (int) $tenant['tenant_id']) : [];

// The online options switched on in config/paymongo.php (GCash / PayMaya / GoTyme).
$payMethods = paymongo_enabled_methods();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Tenant picked the wrong method (or just wants to start over) and
    // doesn't want to wait for PayMongo to eventually decide the old
    // checkout was abandoned. Force-expire that checkout right now so
    // the month opens back up immediately, whatever PayMongo's own
    // status currently says — expiring only fails if it turns out the
    // old attempt already went through, and discard_gcash_checkout only
    // deletes a row that's still Pending, so a real payment is never at risk.
    if (($_POST['action'] ?? '') === 'cancel_payment') {
        $paymentId = (int) ($_POST['payment_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM payments WHERE payment_id = ? AND tenant_id = ? AND payment_status = 'Pending'");
        $stmt->execute([$paymentId, $tenant['tenant_id']]);
        $row = $stmt->fetch();

        if (!$row) {
            flash('error', 'That payment is no longer pending — refresh the page to see its current status.');
        } elseif (empty($row['paymongo_checkout_id'])) {
            flash('error', 'That payment isn\'t an online checkout, so it can\'t be cancelled here.');
        } elseif (discard_gcash_checkout($db, $row)) {
            flash('success', 'Cancelled. You can pick a different payment method now.');
        } else {
            flash('error', 'That payment actually went through — check your history below before trying again.');
        }
        redirect('/tenant/payments.php');
    }

    if (!$contract) {
        flash('error', 'You don\'t have an active contract yet, so there\'s nothing to bill a payment against.');
        redirect('/tenant/payments.php');
    }

    $month = str_input($_POST, 'payment_for_month');
    if ($month === '') {
        flash('error', 'Please tell us which month this payment is for.');
        redirect('/tenant/payments.php');
    }

    // The month comes from a fixed list, not a free-text box, so every
    // row is stored in the same "June 2026" shape. That's what lets the
    // portal tell reliably whether a given month has been settled.
    if (!in_array($month, $monthOptions, true)) {
        flash('error', 'Please pick one of the billing months listed.');
        redirect('/tenant/payments.php');
    }

    // Which option they picked comes from a fixed list too — it decides
    // what PayMongo is allowed to show on the checkout page.
    $methodKey = str_input($_POST, 'payment_method');
    if (!isset($payMethods[$methodKey])) {
        flash('error', 'Please choose how you want to pay.');
        redirect('/tenant/payments.php');
    }
    $payMethod = $payMethods[$methodKey];

    if (!paymongo_configured()) {
        flash('error', 'Online payments are not configured yet. Please contact the dorm administrator or use the available offline payment process.');
        redirect('/tenant/payments.php');
    }

    // A month already settled can't be paid twice. A month still
    // showing Pending usually just means an earlier checkout the tenant
    // backed out of, so ask PayMongo what became of it rather than
    // assuming: only a genuine payment stops them paying now.
    $already = $db->prepare("SELECT * FROM payments
                             WHERE tenant_id = ? AND LOWER(TRIM(COALESCE(payment_for_month,''))) = LOWER(?)
                               AND payment_status IN ('Paid','Pending')
                             ORDER BY FIELD(payment_status,'Paid','Pending'), payment_id DESC LIMIT 1");
    $already->execute([$tenant['tenant_id'], $month]);
    $already = $already->fetch() ?: null;

    if ($already && $already['payment_status'] === 'Pending') {
        // Clicking Pay is the tenant saying they're done with whatever
        // checkout came before, so an abandoned one goes now.
        $already = settle_pending_gcash_payment($db, $already);
    }

    if ($already) {
        flash('error', $already['payment_status'] === 'Paid'
            ? $month . ' is already paid — check your payment history below.'
            : 'Your online payment for ' . $month . ' is still going through. Give it a moment, then refresh this page.');
        redirect('/tenant/payments.php');
    }

    // Amount is always the contract's monthly rent — not something the
    // tenant can type in — so nobody can check out for ₱1 by editing
    // the form. If you ever need partial/custom amounts, that has to
    // be a deliberate admin action, not a client-controlled field.
    $amount = (float) $contract['monthly_rent'];

    $paymentId = null;
    try {
        $db->beginTransaction();
        // Serialize attempts for this tenant. The earlier pre-check is for a
        // friendly message; this locked re-check closes the double-submit race.
        $tenantLock = $db->prepare('SELECT tenant_id FROM tenants WHERE tenant_id = ? FOR UPDATE');
        $tenantLock->execute([$tenant['tenant_id']]);
        if (!$tenantLock->fetchColumn()) {
            throw new RuntimeException('Tenant record is no longer available.');
        }
        $duplicate = $db->prepare("SELECT payment_id, payment_status FROM payments
            WHERE tenant_id = ? AND LOWER(TRIM(COALESCE(payment_for_month,''))) = LOWER(TRIM(?))
              AND payment_status IN ('Paid','Pending') LIMIT 1 FOR UPDATE");
        $duplicate->execute([$tenant['tenant_id'], $month]);
        $existingPayment = $duplicate->fetch();
        if ($existingPayment) {
            $db->rollBack();
            flash('error', $existingPayment['payment_status'] === 'Paid'
                ? $month . ' is already paid — check your payment history below.'
                : 'A payment for ' . $month . ' is already pending. Check payment history before trying again.');
            redirect('/tenant/payments.php');
        }
        $db->prepare('INSERT INTO payments (contract_id, tenant_id, payment_amount, payment_for_month, due_date, payment_status, payment_method) VALUES (?,?,?,?,CURDATE(),"Pending",?)')
           ->execute([$contract['contract_id'], $tenant['tenant_id'], $amount, $month, $payMethod['label']]);
        $paymentId = (int) $db->lastInsertId();
        $db->commit();
        log_action('payment_initiated', 'Tenant #' . $tenant['tenant_id'] . ' started payment #' . $paymentId . ' of ' . peso($amount) . ' for ' . $month);
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log('[Payment initiation] Failed for tenant #' . (int) $tenant['tenant_id'] . ': ' . $e->getMessage());
        flash('error', 'Could not start the payment. Please try again.');
        redirect('/tenant/payments.php');
    }

    // Who PayMongo should record as the payer. Without this the
    // checkout page opens with an empty name field, the browser
    // autofills whoever used it last, and every tenant's rent shows up
    // in the dashboard under that person's name.
    $payer = $db->prepare('SELECT first_name, last_name, email, phone FROM users WHERE user_id = ?');
    $payer->execute([$tenant['user_id']]);
    $payer = $payer->fetch() ?: [];

    try {
        $checkout = paymongo_create_checkout(
            $amount,
            'Rent — ' . $month . ' (Room ' . ($tenant['room_id'] ?? '') . ')',
            'PAY-' . $paymentId,
            ['payment_id' => $paymentId, 'tenant_id' => $tenant['tenant_id']],
            APP_URL . '/tenant/payments.php?checkout=success',
            // The id rides along so backing out of the checkout can
            // clear this exact row instead of guessing at one.
            APP_URL . '/tenant/payments.php?checkout=cancelled&p=' . $paymentId,
            [
                'name'  => trim(($payer['first_name'] ?? '') . ' ' . ($payer['last_name'] ?? '')),
                'email' => $payer['email'] ?? '',
                // PayMongo wants E.164, so the display space comes out.
                'phone' => ph_mobile_e164($payer['phone'] ?? ''),
            ],
            [$payMethod['type']]
        );

        if (empty($checkout['checkout_url']) || empty($checkout['id'])) {
            throw new RuntimeException('PayMongo did not return a checkout link.');
        }

        $db->prepare('UPDATE payments SET paymongo_checkout_id = ? WHERE payment_id = ?')
           ->execute([$checkout['id'], $paymentId]);

        header('Location: ' . $checkout['checkout_url']);
        exit;
    } catch (Throwable $e) {
        // The checkout never got created, or PayMongo errored — don't
        // leave a dangling Pending row a tenant can't do anything about.
        $db->prepare("UPDATE payments SET payment_status = 'Failed' WHERE payment_id = ?")->execute([$paymentId]);
        error_log('[PayMongo checkout] Payment #' . (int) $paymentId . ' failed: ' . $e->getMessage());
        flash('error', 'Could not start the ' . $payMethod['label'] . ' checkout. Please try again later or contact the administrator.');
        redirect('/tenant/payments.php');
    }
}

// Ask PayMongo about anything still Pending before rendering, so coming
// back from the checkout usually lands straight on "Paid" rather than a
// holding message.
$justConfirmed = sync_pending_gcash_payments($db, (int) $tenant['tenant_id']);

// "gcash" is the old name of this parameter — checkouts opened before
// PayMaya/GoTyme were added still return with it.
$returnState = $_GET['checkout'] ?? $_GET['gcash'] ?? null;

if ($returnState === 'success') {
    flash('success', $justConfirmed > 0
        ? 'Payment confirmed — your rent is now marked as Paid. Thank you!'
        : 'Thanks! We\'re confirming your payment now — it will show as Paid here and on your home page automatically, usually within a minute.');
} elseif ($returnState === 'cancelled') {
    // Backing out of the checkout should leave no trace — otherwise the
    // portal keeps showing rent as "being confirmed" for a payment that
    // never happened. Scoped to this tenant's own Pending rows, so a
    // tampered id can't touch anyone else's payment.
    $cancelled = null;
    $stmt = $db->prepare("SELECT * FROM payments WHERE payment_id = ? AND tenant_id = ? AND payment_status = 'Pending'");
    $stmt->execute([(int) ($_GET['p'] ?? 0), $tenant['tenant_id']]);
    if ($row = $stmt->fetch()) {
        $cancelled = settle_pending_gcash_payment($db, $row);
    }

    if ($cancelled && $cancelled['payment_status'] === 'Paid') {
        flash('success', 'Good news — that payment did go through after all. Your rent is marked as Paid.');
    } else {
        flash('error', 'Payment cancelled. No amount was charged.');
    }
}

$rent = tenant_rent_status($db, (int) $tenant['tenant_id'], $contract);

$history = $db->prepare('SELECT * FROM payments WHERE tenant_id = ? ORDER BY COALESCE(payment_date, due_date, created_at) DESC');
$history->execute([$tenant['tenant_id']]);
$history = $history->fetchAll();

$pageTitle = 'Payments';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/page_header.php';
?>
<?php render_page_header('bi-credit-card-fill', 'Payments', 'View rent status, make a payment, and review your payment history.', null, 'Tenant Portal', '/tenant/dashboard.php'); ?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-header"><h2>Pay Rent Online</h2></div>
      <?php if (!$contract): ?>
        <p class="text-muted py-3">You don't have an active contract yet, so there's nothing to pay against right now.</p>
      <?php else: ?>
        <div class="rent-status-card <?= ['Paid' => 'is-paid', 'Pending' => 'is-pending', 'Overdue' => 'is-due', 'Due' => 'is-due', 'None' => 'is-none'][$rent['state']] ?> mt-0 mb-3">
          <div>
            <div class="rent-status-label"><?= $rent['state'] === 'Paid' ? 'Rent' : 'Rent Due' ?> · <?= clean($rent['month']) ?></div>
            <div class="rent-status-amount"><?= peso($rent['amount']) ?></div>
          </div>
          <span class="rent-status-pill"><span class="rent-status-dot"></span><?= clean($rent['label']) ?></span>
        </div>
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Payment Month</label>
            <?php
              // Default to the earliest month on the contract that isn't
              // paid yet, falling back to the current calendar month if
              // it happens to be on the list, then to the first month.
              $defaultMonth = null;
              foreach ($monthOptions as $monthOption) {
                  if (!isset($paidMonths[$monthOption])) { $defaultMonth = $monthOption; break; }
              }
              if ($defaultMonth === null && in_array(current_billing_month(), $monthOptions, true)) {
                  $defaultMonth = current_billing_month();
              }
              if ($defaultMonth === null && $monthOptions) {
                  $defaultMonth = $monthOptions[0];
              }
            ?>
            <select class="form-select" name="payment_for_month" required>
              <?php foreach ($monthOptions as $monthOption): $isPaid = isset($paidMonths[$monthOption]); ?>
                <option value="<?= clean($monthOption) ?>"<?= $monthOption === $defaultMonth ? ' selected' : '' ?><?= $isPaid ? ' disabled' : '' ?>><?= clean($monthOption) ?><?= $isPaid ? ' — ✓ Paid' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!$monthOptions): ?>
              <div class="form-text text-danger">This contract has no billable months on record — please contact the office.</div>
            <?php endif; ?>
          </div>
          <div class="mb-3">
            <label class="form-label">Amount</label>
            <input type="text" class="form-control" value="<?= peso($contract['monthly_rent']) ?>" disabled>
            <div class="form-text">Your monthly rent, set on your contract.</div>
          </div>
          <?php if (!$payMethods): ?>
            <div class="alert alert-warning mb-0">Online payment isn't available right now. Please pay at the office.</div>
          <?php else: ?>
          <div class="mb-3">
            <label class="form-label">Pay with</label>
            <div class="pay-method-list">
              <?php $firstMethod = true; foreach ($payMethods as $key => $m): ?>
                <label class="pay-method">
                  <input type="radio" name="payment_method" value="<?= clean($key) ?>" required<?= $firstMethod ? ' checked' : '' ?>>
                  <span class="pay-method-body">
                    <span class="pay-method-icon"><i class="bi <?= clean($m['icon']) ?>"></i></span>
                    <span class="pay-method-text"><strong><?= clean($m['label']) ?></strong><small><?= clean($m['hint']) ?></small></span>
                    <span class="pay-method-check"><i class="bi bi-check-circle-fill"></i></span>
                  </span>
                </label>
              <?php $firstMethod = false; endforeach; ?>
            </div>
          </div>
          <p class="text-muted small mt-2 mb-0">You'll be taken to PayMongo's secure checkout for the option you picked. Your payment is confirmed automatically — no need to upload a receipt.</p>
          <button type="submit" class="btn btn-danger w-100 mt-3">
            <i class="bi bi-credit-card-fill"></i> Pay Now
          </button>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-header"><h2>Payment History</h2></div>
      <?php if (!$history): ?><p class="text-muted py-3">No payments yet.</p><?php endif; ?>
      <?php foreach ($history as $p): ?>
        <div class="list-row">
          <div>
            <strong><?= clean($p['payment_for_month'] ?: '—') ?></strong>
            <div class="text-muted small"><?= $p['payment_date'] ? clean(date('M j, Y', strtotime($p['payment_date']))) : 'Awaiting confirmation' ?><?= $p['payment_method'] ? ' · ' . clean($p['payment_method']) : '' ?></div>
          </div>
          <div class="text-end">
            <?= peso($p['payment_amount']) ?><br>
            <span class="badge badge-<?= status_badge_class($p['payment_status']) ?>"><?= clean($p['payment_status']) ?></span>
            <?php if ($p['payment_status'] === 'Pending' && $p['paymongo_checkout_id']): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Cancel this payment attempt? You\'ll be able to pick a different method right after.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel_payment">
                <input type="hidden" name="payment_id" value="<?= $p['payment_id'] ?>">
                <button type="submit" class="btn btn-link btn-sm p-0 d-block text-muted">Cancel &amp; retry</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php
include __DIR__ . '/../includes/footer.php';
?>
