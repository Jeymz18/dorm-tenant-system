<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();

// Move leases whose end date has come and gone into 'Expired' (and
// leases inside the 30-day window into 'Expiring Soon') before anything
// on this page reads a contract_status.
refresh_contract_statuses($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_contract') {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $rent     = (float) ($_POST['monthly_rent'] ?? 0);
        $deposit  = (float) ($_POST['security_deposit'] ?? 0);
        $start    = $_POST['contract_start'] ?? '';
        $end      = $_POST['contract_end'] ?? '';

        $t = $db->prepare('SELECT room_id FROM tenants WHERE tenant_id = ?');
        $t->execute([$tenantId]);
        $t = $t->fetch();

        $existing = $db->prepare("SELECT COUNT(*) c FROM contracts WHERE tenant_id = ? AND contract_status IN ('Active','Expiring Soon')");
        $existing->execute([$tenantId]);
        $hasActiveContract = (int) $existing->fetch()['c'] > 0;

        if (!$t || !$t['room_id']) {
            flash('error', 'This tenant needs a room assigned (Property Management) before you can create a contract.');
        } elseif ($hasActiveContract) {
            flash('error', 'This tenant already has an active contract. End it first if you need to replace it.');
        } elseif ($rent <= 0 || !$start || !$end || $end <= $start) {
            flash('error', 'Please provide a valid rent amount and date range.');
        } else {
            try {
                $file = handle_upload('contract_file', 'contracts', ['pdf']);
                $db->prepare('INSERT INTO contracts (tenant_id, room_id, monthly_rent, security_deposit, contract_start, contract_end, contract_file) VALUES (?,?,?,?,?,?,?)')
                   ->execute([$tenantId, $t['room_id'], $rent, $deposit, $start, $end, $file]);
                log_activity($db, 'contract_created', 'New contract created for tenant #' . $tenantId, $tenantId);
                flash('success', 'Contract created.');
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
    }

    if ($action === 'record_payment') {
        $contractId = (int) ($_POST['contract_id'] ?? 0);
        $tenantId   = (int) ($_POST['tenant_id'] ?? 0);
        $amount     = (float) ($_POST['payment_amount'] ?? 0);
        $forMonth   = str_input($_POST, 'payment_for_month');
        $method     = str_input($_POST, 'payment_method');

        $validPair = $db->prepare('SELECT contract_id FROM contracts WHERE contract_id = ? AND tenant_id = ?');
        $validPair->execute([$contractId, $tenantId]);

        if ($amount <= 0 || $forMonth === '' || strlen($forMonth) > 20) {
            flash('error', 'Please provide a valid amount and billing month.');
        } elseif (!$validPair->fetch()) {
            flash('error', 'That contract could not be found for this tenant — it may have changed since the page loaded. Please refresh and try again.');
        } else {
            try {
                $db->beginTransaction();
                $tenantLock = $db->prepare('SELECT tenant_id FROM tenants WHERE tenant_id = ? FOR UPDATE');
                $tenantLock->execute([$tenantId]);
                if (!$tenantLock->fetchColumn()) {
                    throw new RuntimeException('Tenant record is no longer available.');
                }
                $existing = $db->prepare("SELECT payment_id, payment_status FROM payments WHERE tenant_id = ? AND LOWER(TRIM(COALESCE(payment_for_month,''))) = LOWER(TRIM(?)) AND payment_status IN ('Paid','Pending') LIMIT 1 FOR UPDATE");
                $existing->execute([$tenantId, $forMonth]);
                $duplicate = $existing->fetch();
                if ($duplicate) {
                    $db->rollBack();
                    flash('error', $duplicate['payment_status'] === 'Paid'
                        ? 'This tenant already has a recorded payment for ' . $forMonth . '.'
                        : 'This tenant already has a pending payment for ' . $forMonth . '. Resolve it before recording another payment.');
                } else {
                    $db->prepare('INSERT INTO payments (contract_id, tenant_id, payment_amount, payment_for_month, payment_date, paid_at, due_date, payment_status, payment_method) VALUES (?,?,?,?,CURDATE(),NOW(),CURDATE(),"Paid",?)')
                       ->execute([$contractId, $tenantId, $amount, $forMonth, $method ?: 'Cash']);
                    $paymentId = (int) $db->lastInsertId();
                    $db->commit();
                    send_payment_invoice($db, $paymentId);
                    log_action('payment_recorded', 'Recorded payment #' . $paymentId . ' of ' . peso($amount) . ' for tenant #' . $tenantId . ' (' . $forMonth . ')');
                    log_activity($db, 'payment_recorded', 'Payment of ' . peso($amount) . ' recorded for ' . $forMonth, $tenantId);
                    flash('success', 'Payment recorded.');
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                error_log('[Admin payment] Failed to record payment: ' . $e->getMessage());
                flash('error', 'Could not record this payment. Please refresh and try again.');
            }
        }
    }

    // ---- Renew / continue an existing lease ---------------------------
    // The contract row is REUSED rather than replaced: same contract_id,
    // new end date. That keeps every payment already recorded against
    // this lease attached to it instead of orphaning the history behind
    // a brand new contract.
    if ($action === 'renew_contract') {
        $contractId = (int) ($_POST['contract_id'] ?? 0);
        $newEnd     = str_input($_POST, 'new_contract_end');
        $newRent    = (float) ($_POST['monthly_rent'] ?? 0);

        $c = $db->prepare("
            SELECT c.*, u.first_name, u.last_name, u.email, r.room_number
            FROM contracts c
            JOIN tenants t ON t.tenant_id = c.tenant_id
            JOIN users u ON u.user_id = t.user_id
            JOIN dorm_rooms r ON r.room_id = c.room_id
            WHERE c.contract_id = ?
        ");
        $c->execute([$contractId]);
        $c = $c->fetch();

        $validDate = $newEnd !== '' && (bool) strtotime($newEnd);

        if (!$c) {
            flash('error', 'That contract could not be found. Please refresh and try again.');
        } elseif ($c['contract_status'] === 'Terminated') {
            flash('error', 'This contract was terminated and can\'t be renewed. Create a new contract for this tenant instead.');
        } elseif (!$validDate) {
            flash('error', 'Please give the renewal a valid new end date.');
        } elseif ($newEnd <= date('Y-m-d')) {
            flash('error', 'The new end date has to be in the future.');
        } elseif ($newEnd <= $c['contract_end']) {
            flash('error', 'The new end date has to be later than the current one (' . date('M j, Y', strtotime($c['contract_end'])) . ').');
        } elseif ($newRent <= 0) {
            flash('error', 'Please provide a valid monthly rent for the renewed term.');
        } else {
            $previousEnd = $c['contract_end'];
            $db->prepare("
                UPDATE contracts
                   SET contract_end = ?,
                       monthly_rent = ?,
                       contract_status = 'Active',
                       renewal_count = renewal_count + 1,
                       last_renewed_at = NOW(),
                       renewal_requested_at = NULL
                 WHERE contract_id = ?
            ")->execute([$newEnd, $newRent, $contractId]);

            log_activity($db, 'contract_renewed', $c['first_name'] . ' ' . $c['last_name'] . '\'s contract renewed through ' . date('M j, Y', strtotime($newEnd)), $c['tenant_id']);
            flash('success', $c['first_name'] . '\'s contract has been renewed through ' . date('F j, Y', strtotime($newEnd)) . '.');

            $subject = 'Your dorm contract has been renewed';
            $message = "Hi {$c['first_name']}, your contract for Room {$c['room_number']} has been renewed. "
                     . 'It now runs through ' . date('F j, Y', strtotime($newEnd)) . ' at '
                     . peso($newRent) . ' per month'
                     . ($previousEnd !== $newEnd ? ' (previously ending ' . date('F j, Y', strtotime($previousEnd)) . ')' : '') . '.';

            $db->prepare("INSERT INTO notifications (sender_id, type, subject, message, target_type, target_value)
                          VALUES (?, 'Contract Expiry Alert', ?, ?, 'tenant', ?)")
               ->execute([current_user_id(), $subject, $message, $c['tenant_id']]);
            send_email_alert($c['email'], $c['first_name'], $subject, email_template('Contract renewed', $message));
        }
    }

    // ---- Officially end a lease ---------------------------------------
    if ($action === 'terminate_contract') {
        $contractId   = (int) ($_POST['contract_id'] ?? 0);
        $reason       = str_input($_POST, 'termination_reason');
        $alsoCheckOut = isset($_POST['checkout_tenant']);

        $c = $db->prepare("
            SELECT c.*, t.status AS tenant_status, t.room_id AS tenant_room_id,
                   u.first_name, u.last_name, u.email, r.room_number
            FROM contracts c
            JOIN tenants t ON t.tenant_id = c.tenant_id
            JOIN users u ON u.user_id = t.user_id
            JOIN dorm_rooms r ON r.room_id = c.room_id
            WHERE c.contract_id = ?
        ");
        $c->execute([$contractId]);
        $c = $c->fetch();

        if (!$c) {
            flash('error', 'That contract could not be found. Please refresh and try again.');
        } elseif ($c['contract_status'] === 'Terminated') {
            flash('error', 'This contract has already been terminated.');
        } else {
            $db->beginTransaction();
            try {
                $db->prepare("
                    UPDATE contracts
                       SET contract_status = 'Terminated',
                           terminated_at = NOW(),
                           termination_reason = ?,
                           renewal_requested_at = NULL
                     WHERE contract_id = ?
                ")->execute([$reason ?: null, $contractId]);

                // Ending the lease and moving the tenant out are separate
                // decisions — an admin might terminate purely to replace
                // the contract — so the check-out half is opt-in and
                // mirrors the existing check-out logic exactly.
                if ($alsoCheckOut && in_array($c['tenant_status'], ['Active', 'Pending'], true)) {
                    $db->prepare("UPDATE tenants SET status = 'Checked Out', checkout_date = CURDATE() WHERE tenant_id = ?")
                       ->execute([$c['tenant_id']]);
                    if ($c['tenant_room_id']) {
                        recalc_room_status($db, (int) $c['tenant_room_id']);
                    }
                }
                $db->commit();

                log_activity($db, 'contract_terminated', $c['first_name'] . ' ' . $c['last_name'] . '\'s contract was terminated', $c['tenant_id']);
                flash('success', $c['first_name'] . '\'s contract has been terminated'
                    . ($alsoCheckOut ? ' and they have been checked out — Room ' . $c['room_number'] . ' is available again.' : '.'));

                $subject = 'Your dorm contract has ended';
                $message = "Hi {$c['first_name']}, your contract for Room {$c['room_number']} has been officially ended by the dorm office."
                         . ($reason !== '' ? " Reason given: {$reason}" : '')
                         . ' If you have questions, please contact the office.';

                $db->prepare("INSERT INTO notifications (sender_id, type, subject, message, target_type, target_value)
                              VALUES (?, 'Contract Expiry Alert', ?, ?, 'tenant', ?)")
                   ->execute([current_user_id(), $subject, $message, $c['tenant_id']]);
                send_email_alert($c['email'], $c['first_name'], $subject, email_template('Contract ended', $message));
            } catch (Exception $e) {
                $db->rollBack();
                flash('error', 'Could not terminate that contract. Please try again.');
            }
        }
    }

    if ($action === 'verify_payment') {
        $paymentId = (int) ($_POST['payment_id'] ?? 0);
        $newStatus = str_input($_POST, 'new_status', 'Paid');
        if (!in_array($newStatus, ['Paid', 'Pending', 'Overdue', 'Failed'], true)) {
            flash('error', 'Invalid payment status.');
            redirect('/admin/payments.php');
        }
        $paymentRow = $db->prepare('SELECT tenant_id, payment_status, payment_method, paymongo_checkout_id FROM payments WHERE payment_id = ?');
        $paymentRow->execute([$paymentId]);
        $paymentRow = $paymentRow->fetch();
        $paymentTenantId = $paymentRow['tenant_id'] ?? null;
        $isOnlinePayment = $paymentRow && $paymentRow['paymongo_checkout_id'] && paymongo_method_by_label($paymentRow['payment_method']);

        // A Failed row is a checkout that never went through — no money
        // actually came in, so it can't be promoted straight to Paid.
        // Only a genuinely outstanding payment (Pending/Overdue) can be
        // confirmed this way.
        if (!$paymentRow) {
            flash('error', 'That payment could not be found. Please refresh and try again.');
        } elseif ($newStatus === 'Paid' && $isOnlinePayment) {
            flash('error', 'This is an online ' . $paymentRow['payment_method'] . ' payment — it is confirmed automatically once PayMongo verifies it and cannot be marked Paid manually.');
        } elseif ($newStatus === 'Paid' && !in_array($paymentRow['payment_status'], ['Pending', 'Overdue'], true)) {
            flash('error', 'That payment is ' . $paymentRow['payment_status'] . ' and can\'t be marked Paid directly. Record a new payment for the tenant instead if they paid in cash.');
        } else {
            $db->prepare('UPDATE payments SET payment_status = ?, payment_date = IF(? = "Paid", CURDATE(), payment_date), paid_at = IF(? = "Paid", COALESCE(paid_at, NOW()), paid_at) WHERE payment_id = ?')
              ->execute([$newStatus, $newStatus, $newStatus, $paymentId]);
            if ($newStatus === 'Paid' && $paymentRow['payment_status'] !== 'Paid') {
              send_payment_invoice($db, $paymentId);
            }
            log_action('payment_status_changed', 'Changed payment #' . $paymentId . ' status from ' . $paymentRow['payment_status'] . ' to ' . $newStatus);
            log_activity($db, 'payment_verified', 'Payment #' . $paymentId . ' marked as ' . $newStatus, $paymentTenantId);
            flash('success', 'Payment marked as ' . $newStatus . '.');
        }
    }

    redirect('/admin/payments.php');
}

$paidTotal = (float) $db->query("SELECT COALESCE(SUM(payment_amount),0) t FROM payments WHERE payment_status='Paid' AND MONTH(payment_date)=MONTH(CURDATE())")->fetch()['t'];
$pending   = $db->query("SELECT COUNT(*) c, COALESCE(SUM(payment_amount),0) t FROM payments WHERE payment_status='Pending'")->fetch();
$overdue   = $db->query("SELECT COUNT(*) c, COALESCE(SUM(payment_amount),0) t FROM payments WHERE payment_status='Overdue'")->fetch();

$activeTab = str_input($_GET, 'tab') ?: 'payments';
if (!in_array($activeTab, ['payments', 'contracts', 'expirations'], true)) {
    $activeTab = 'payments';
}
$search = str_input($_GET, 'q');

$paymentStatusFilter = $_GET['status'] ?? 'all';
if (!in_array($paymentStatusFilter, ['all', 'Paid', 'Pending', 'Overdue'], true)) {
    $paymentStatusFilter = 'all';
}
$pWhere = [];
$pParams = [];
if ($paymentStatusFilter !== 'all') {
    $pWhere[] = 'p.payment_status = ?';
    $pParams[] = $paymentStatusFilter;
}
if ($activeTab === 'payments' && $search !== '') {
    $pWhere[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR r.room_number LIKE ?)';
    $like = "%$search%";
    array_push($pParams, $like, $like, $like);
}
$pWhereSql = $pWhere ? 'WHERE ' . implode(' AND ', $pWhere) : '';

$paymentStatusCounts = ['Paid' => 0, 'Pending' => 0, 'Overdue' => 0];
foreach ($db->query("SELECT payment_status, COUNT(*) c FROM payments GROUP BY payment_status") as $row) {
    if (isset($paymentStatusCounts[$row['payment_status']])) {
        $paymentStatusCounts[$row['payment_status']] = (int) $row['c'];
    }
}
$paymentTotalCount = array_sum($paymentStatusCounts);

$paymentsResult = paginate(
    $db,
    "SELECT p.*, u.first_name, u.last_name, r.room_number
     FROM payments p
     JOIN tenants t ON t.tenant_id = p.tenant_id
     JOIN users u ON u.user_id = t.user_id
     LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
     $pWhereSql
     ORDER BY FIELD(p.payment_status,'Pending','Overdue','Paid'), p.due_date DESC",
    "SELECT COUNT(*) c FROM payments p
     JOIN tenants t ON t.tenant_id = p.tenant_id
     JOIN users u ON u.user_id = t.user_id
     LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
     $pWhereSql",
    $pParams,
    15
);
$payments = $paymentsResult['rows'];

// Contracts list is capped rather than fully paginated — it grows far
// slower than payments (roughly one row per tenancy, not per month),
// so a generous limit covers realistic use without a second, separate
// pagination control competing with the payments list above for the
// same ?page= query param.
$contracts = $db->query("
    SELECT c.*, u.first_name, u.last_name, r.room_number
    FROM contracts c
    JOIN tenants t ON t.tenant_id = c.tenant_id
    JOIN users u ON u.user_id = t.user_id
    JOIN dorm_rooms r ON r.room_id = c.room_id
    ORDER BY FIELD(c.contract_status,'Expired','Expiring Soon','Active','Terminated'), c.contract_end ASC
    LIMIT 30
")->fetchAll();

if ($activeTab === 'contracts' && $search !== '') {
    $contracts = array_values(array_filter($contracts, function ($c) use ($search) {
        return stripos($c['first_name'] . ' ' . $c['last_name'] . ' ' . $c['room_number'], $search) !== false;
    }));
}

// How many leases need a decision right now (expired, or expiring
// inside the 30-day window). Counted across ALL contracts, not just the
// 30 listed below.
$contractCounts = $db->query("
    SELECT
      SUM(contract_status = 'Expired') AS expired,
      SUM(contract_status = 'Expiring Soon') AS expiring,
      SUM(renewal_requested_at IS NOT NULL AND contract_status <> 'Terminated') AS requested
    FROM contracts
")->fetch();

// Tenants with an assigned room but no live contract yet. 'Expiring
// Soon' counts as live — it's the same lease as 'Active', just inside
// the warning window — so it can be renewed rather than duplicated.
$contractableTenants = $db->query("
    SELECT t.tenant_id, u.first_name, u.last_name, r.room_number, r.monthly_rate
    FROM tenants t
    JOIN users u ON u.user_id = t.user_id
    JOIN dorm_rooms r ON r.room_id = t.room_id
    WHERE t.room_id IS NOT NULL
      AND t.tenant_id NOT IN (SELECT tenant_id FROM contracts WHERE contract_status IN ('Active','Expiring Soon'))
    ORDER BY u.first_name
")->fetchAll();

// An expired lease can still take a payment — the final month's rent
// often lands after the term is up. Only a terminated one is closed
// to new payments.
$activeContracts = array_filter($contracts, fn($c) => $c['contract_status'] !== 'Terminated');

$headerActions = [
    'payments'    => '<button type="button" class="btn btn-top-action" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="bi bi-plus-lg"></i> Record Payment</button>',
    'contracts'   => '<button type="button" class="btn btn-top-action" data-bs-toggle="modal" data-bs-target="#contractModal"><i class="bi bi-upload"></i> Upload Contract</button>',
    'expirations' => null,
];

$pageTitle = 'Payment & Contract Management';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/module_tabs.php';
require_once __DIR__ . '/../includes/page_header.php';
render_page_header(
    'bi-wallet2',
    'Payments & Contracts',
    'Track payments, upload contracts, and monitor upcoming expirations.',
    $headerActions[$activeTab]
);
render_module_tabs([
  ['key' => 'payments', 'label' => 'Track Payments', 'href' => '/admin/payments.php?tab=payments'],
  ['key' => 'contracts', 'label' => 'Manage Contracts', 'href' => '/admin/payments.php?tab=contracts'],
  ['key' => 'expirations', 'label' => 'Expirations', 'href' => '/admin/payments.php?tab=expirations'],
], $activeTab); ?>

<div class="stat-grid stat-grid-3">
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Collected This Month</div><div class="stat-value text-success"><?= peso($paidTotal) ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-check-lg"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Pending</div><div class="stat-value"><?= $pending['c'] ?></div><div class="stat-sub"><?= peso($pending['t']) ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-clock-fill"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Overdue</div><div class="stat-value text-danger"><?= $overdue['c'] ?></div><div class="stat-sub"><?= peso($overdue['t']) ?></div></div><div class="icon-wrapper icon-circle stat-icon stat-icon-outline"><i class="bi bi-exclamation-triangle-fill"></i></div></div>
</div>

<?php if ($activeTab === 'payments'): ?>
<div class="panel mt-2" id="payments">
  <div class="filter-toolbar">
    <form class="search-box" method="get">
      <i class="bi bi-search"></i>
      <input type="hidden" name="tab" value="payments">
      <?php if ($paymentStatusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= clean($paymentStatusFilter) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= clean($search) ?>" placeholder="Search tenant, room…" class="form-control form-control-sm">
    </form>
    <div class="filter-pills">
      <?php $paymentPills = ['all' => 'All', 'Paid' => 'Paid', 'Pending' => 'Pending', 'Overdue' => 'Overdue'];
      foreach ($paymentPills as $key => $label):
          $count = $key === 'all' ? $paymentTotalCount : $paymentStatusCounts[$key];
          $qs = http_build_query(array_filter(['tab' => 'payments', 'status' => $key === 'all' ? null : $key, 'q' => $search ?: null]));
      ?>
        <a href="?<?= $qs ?>" class="filter-pill <?= $paymentStatusFilter === $key ? 'active' : '' ?>"><?= clean($label) ?> <span class="pill-count"><?= $count ?></span></a>
      <?php endforeach; ?>
    </div>
    <span class="filter-result-count"><?= $paymentsResult['total'] ?> record<?= $paymentsResult['total'] === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-responsive">
    <table class="table app-table align-middle">
      <thead><tr><th>Tenant</th><th>Month</th><th>Amount</th><th>Method</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$payments): ?><tr><td colspan="6" class="text-center text-muted py-4">No payments found.</td></tr><?php endif; ?>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td><?= clean($p['first_name'] . ' ' . $p['last_name']) ?><div class="text-muted small"><?= $p['room_number'] ? 'Room ' . clean($p['room_number']) : '' ?></div></td>
          <td class="small"><?= clean($p['payment_for_month'] ?: '—') ?></td>
          <td><?= peso($p['payment_amount']) ?></td>
          <td class="small">
            <?php $onlineMethod = $p['paymongo_checkout_id'] ? paymongo_method_by_label($p['payment_method']) : null; ?>
            <?php if ($onlineMethod): ?>
              <span class="badge badge-info"><i class="bi <?= clean($onlineMethod['icon']) ?>"></i> <?= clean($onlineMethod['label']) ?></span>
            <?php else: ?>
              <?= clean($p['payment_method'] ?: '—') ?>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-<?= status_badge_class($p['payment_status']) ?>"><?= clean($p['payment_status']) ?></span></td>
          <td class="text-end">
            <?php $isOnlinePayment = $onlineMethod !== null; ?>
            <?php if ($isOnlinePayment && in_array($p['payment_status'], ['Pending', 'Overdue'], true)): ?>
              <span class="text-muted small">Awaiting <?= clean($onlineMethod['label']) ?> confirmation</span>
            <?php elseif (in_array($p['payment_status'], ['Pending', 'Overdue'], true)): ?>
              <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="verify_payment"><input type="hidden" name="payment_id" value="<?= $p['payment_id'] ?>"><input type="hidden" name="new_status" value="Paid"><button class="btn btn-sm btn-action-primary">Mark Paid</button></form>
            <?php elseif ($p['payment_status'] === 'Paid'): ?>
              <span class="text-muted small"><?= clean(date('n/j/Y', strtotime($p['payment_date']))) ?></span>
            <?php else: ?>
              <span class="text-muted small">— no action</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($paymentsResult['page'], $paymentsResult['totalPages']) ?>
</div>

<?php elseif ($activeTab === 'contracts'): ?>
<div class="panel mt-2" id="contracts">
  <div class="panel-header"><h2>Contracts</h2></div>
  <form class="search-box mb-3" method="get">
    <i class="bi bi-search"></i>
    <input type="hidden" name="tab" value="contracts">
    <input type="search" name="q" value="<?= clean($search) ?>" placeholder="Search tenant or room…" class="form-control form-control-sm">
  </form>
  <?php if ((int) ($contractCounts['expired'] ?? 0) || (int) ($contractCounts['expiring'] ?? 0) || (int) ($contractCounts['requested'] ?? 0)): ?>
    <div class="contract-summary">
      <?php if ((int) $contractCounts['expired']): ?><span class="badge badge-danger"><?= (int) $contractCounts['expired'] ?> expired</span><?php endif; ?>
      <?php if ((int) $contractCounts['expiring']): ?><span class="badge badge-warning"><?= (int) $contractCounts['expiring'] ?> expiring soon</span><?php endif; ?>
      <?php if ((int) $contractCounts['requested']): ?><span class="badge badge-info"><?= (int) $contractCounts['requested'] ?> renewal request<?= (int) $contractCounts['requested'] === 1 ? '' : 's' ?></span><?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="contracts-grid">
    <?php if (!$contracts): ?><p class="text-muted py-3">No contracts found.</p><?php endif; ?>
    <?php foreach ($contracts as $c):
          $daysLeft   = days_until($c['contract_end']);
          $isExpired  = $c['contract_status'] === 'Expired';
          $isExpiring = $c['contract_status'] === 'Expiring Soon' || ($c['contract_status'] === 'Active' && $daysLeft <= 30);
          $isClosed   = $c['contract_status'] === 'Terminated';
          $requested  = !empty($c['renewal_requested_at']);
          // Sensible default for the renew form: one more year from
          // whichever is later — the old end date or today.
          $renewFrom  = max($c['contract_end'], date('Y-m-d'));
          $suggestedEnd = date('Y-m-d', strtotime($renewFrom . ' +1 year'));
        ?>
          <div class="contract-card<?= $isExpired ? ' is-expired' : ($isExpiring ? ' is-expiring' : '') ?>">
            <div class="contract-card-top">
              <span class="contract-card-icon"><i class="bi bi-file-earmark-text-fill"></i></span>
              <?php if ($c['contract_file']): ?><a href="<?= BASE_URL . '/' . clean($c['contract_file']) ?>" target="_blank" class="contract-card-download" title="Download"><i class="bi bi-download"></i></a><?php endif; ?>
            </div>
            <strong><?= clean($c['first_name'] . ' ' . $c['last_name']) ?></strong>
            <div class="text-muted small">Room <?= clean($c['room_number']) ?></div>
            <div class="contract-card-meta"><i class="bi bi-calendar-range"></i> <?= clean(date('M Y', strtotime($c['contract_start']))) ?> – <?= clean(date('M Y', strtotime($c['contract_end']))) ?></div>
            <div class="contract-card-meta"><i class="bi bi-clock-history"></i> Uploaded <?= clean(date('M j, Y', strtotime($c['created_at']))) ?></div>
            <?php if ($requested && !$isClosed): ?>
              <div class="small mt-1" style="color:var(--blue-text);"><i class="bi bi-hand-index-thumb-fill"></i> Renewal requested <?= clean(date('M j, Y', strtotime($c['renewal_requested_at']))) ?></div>
            <?php endif; ?>
            <?php if ((int) ($c['renewal_count'] ?? 0) > 0): ?>
              <div class="text-muted small">Renewed <?= (int) $c['renewal_count'] ?> time<?= (int) $c['renewal_count'] === 1 ? '' : 's' ?></div>
            <?php endif; ?>
            <?php if ($isClosed && !empty($c['termination_reason'])): ?>
              <div class="text-muted small">Ended: <?= clean($c['termination_reason']) ?></div>
            <?php endif; ?>
            <div class="mt-2">
              <span class="badge badge-<?= status_badge_class($c['contract_status']) ?>">
                <?= $isExpired ? 'Expired ' . abs($daysLeft) . 'd ago' : ($isExpiring ? 'Expires in ' . $daysLeft . 'd' : clean($c['contract_status'])) ?>
              </span>
            </div>
            <?php if (!$isClosed): ?>
              <div class="contract-card-actions">
                <button type="button" class="btn btn-sm <?= $isExpired || $isExpiring ? 'btn-action-primary' : 'btn-action-outline' ?>"
                  data-bs-toggle="modal" data-bs-target="#renewContractModal"
                  data-id="<?= $c['contract_id'] ?>"
                  data-name="<?= clean($c['first_name'] . ' ' . $c['last_name']) ?>"
                  data-room="<?= clean($c['room_number']) ?>"
                  data-end="<?= clean(date('M j, Y', strtotime($c['contract_end']))) ?>"
                  data-suggested="<?= clean($suggestedEnd) ?>"
                  data-base="<?= clean($renewFrom) ?>"
                  data-min="<?= clean(date('Y-m-d', strtotime($renewFrom . ' +1 day'))) ?>"
                  data-rent="<?= clean((string) $c['monthly_rent']) ?>"><i class="bi bi-arrow-repeat"></i> Renew Contract</button>
                <button type="button" class="btn btn-sm btn-action-outline"
                  data-bs-toggle="modal" data-bs-target="#terminateContractModal"
                  data-id="<?= $c['contract_id'] ?>"
                  data-name="<?= clean($c['first_name'] . ' ' . $c['last_name']) ?>"
                  data-room="<?= clean($c['room_number']) ?>"><i class="bi bi-x-octagon"></i> Terminate Contract</button>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
  </div>
</div>

<?php else: /* expirations */ ?>
<div class="panel mt-2" id="expirations">
  <div class="panel-header"><h2>Expirations</h2></div>
  <?php
    $expiringSoonList = array_filter($contracts, function ($c) {
        return $c['contract_status'] !== 'Terminated' && days_until($c['contract_end']) <= 60;
    });
  ?>
  <?php if ($expiringSoonList): ?>
    <div class="callout callout-warning">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <div><?= count($expiringSoonList) ?> contract<?= count($expiringSoonList) === 1 ? '' : 's' ?> expiring within 60 days.</div>
    </div>
  <?php else: ?>
    <p class="text-muted mb-0">No contracts expiring within the next 60 days.</p>
  <?php endif; ?>
  <?php foreach ($expiringSoonList as $c): $d = days_until($c['contract_end']); ?>
    <div class="contract-item<?= $d < 0 ? ' is-expired' : ' is-expiring' ?>">
      <div>
        <strong><?= clean($c['first_name'] . ' ' . $c['last_name']) ?></strong>
        <div class="text-muted small">Room <?= clean($c['room_number']) ?> · <?= clean(date('M j, Y', strtotime($c['contract_start']))) ?> – <?= clean(date('M j, Y', strtotime($c['contract_end']))) ?></div>
      </div>
      <div class="text-end"><span class="badge badge-<?= $d < 0 ? 'danger' : 'warning' ?>"><?= $d < 0 ? 'Expired ' . abs($d) . 'd ago' : 'Expires in ' . $d . 'd' ?></span></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- New Contract Modal -->
<div class="modal fade" id="contractModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_contract">
        <div class="modal-header"><h5 class="modal-title">Upload Contract</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?php if (!$contractableTenants): ?>
            <p class="text-muted">Every room-assigned tenant already has an active contract. Assign a tenant to a room first in Property Management.</p>
          <?php else: ?>
            <label class="form-label">Tenant</label>
            <select class="form-select mb-3" name="tenant_id" id="contract_tenant_select" required>
              <option value="">Choose…</option>
              <?php foreach ($contractableTenants as $t): ?>
                <option value="<?= $t['tenant_id'] ?>" data-rate="<?= $t['monthly_rate'] ?>"><?= clean($t['first_name'] . ' ' . $t['last_name']) ?> — Room <?= clean($t['room_number']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="row g-3">
              <div class="col-md-6"><label class="form-label">Monthly Rent (₱)</label><input type="number" step="0.01" min="0" class="form-control" name="monthly_rent" id="contract_rent" required></div>
              <div class="col-md-6"><label class="form-label">Security Deposit (₱)</label><input type="number" step="0.01" min="0" class="form-control" name="security_deposit"></div>
            </div>
            <div class="row g-3 mt-0">
              <div class="col-md-6"><label class="form-label">Start Date</label><input type="date" class="form-control" name="contract_start" required></div>
              <div class="col-md-6"><label class="form-label">End Date</label><input type="date" class="form-control" name="contract_end" required></div>
            </div>
            <div class="mb-1 mt-3">
              <label class="form-label">Contract File (PDF, optional)</label>
              <label class="dropzone d-block">
                <span class="dz-icon"><i class="bi bi-upload"></i></span>
                <div>Click to upload</div>
                <div class="dz-hint">PDF only, max 10MB</div>
                <input type="file" name="contract_file" accept="application/pdf">
              </label>
            </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><?php if ($contractableTenants): ?><button class="btn btn-sm btn-action-primary">Create Contract</button><?php endif; ?></div>
      </form>
    </div>
  </div>
</div>

<!-- Renew Contract Modal -->
<div class="modal fade" id="renewContractModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="renew_contract">
        <input type="hidden" name="contract_id" id="rc_contract_id">
        <div class="modal-header"><h5 class="modal-title">Renew Contract</h5><button class="btn-close" data-bs-dismiss="modal" type="button"></button></div>
        <div class="modal-body">
          <p class="text-muted">Continuing <strong id="rc_name"></strong>'s lease for Room <span id="rc_room"></span>. The same contract carries on — payment history stays attached — with a new end date.</p>
          <div class="mb-3"><label class="form-label">Current End Date</label><input class="form-control" id="rc_current_end" disabled></div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">New End Date</label>
              <input type="date" class="form-control" name="new_contract_end" id="rc_new_end" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Monthly Rent (₱)</label>
              <input type="number" step="0.01" min="0" class="form-control" name="monthly_rent" id="rc_rent" required>
              <div class="form-text">Adjust if the rate changes for the new term.</div>
            </div>
          </div>
          <div class="quick-term-buttons mt-3">
            <span class="text-muted small me-1">Quick set:</span>
            <button type="button" class="btn btn-sm btn-action-outline" data-months="3">+3 months</button>
            <button type="button" class="btn btn-sm btn-action-outline" data-months="6">+6 months</button>
            <button type="button" class="btn btn-sm btn-action-outline" data-months="12">+1 year</button>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-sm btn-action-primary w-100"><i class="bi bi-arrow-repeat"></i> Renew Contract</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Terminate Contract Modal -->
<div class="modal fade" id="terminateContractModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" onsubmit="return confirm('Terminate this contract? This cannot be undone — you would have to create a new contract to put the tenant back under a lease.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="terminate_contract">
        <input type="hidden" name="contract_id" id="tc_contract_id">
        <div class="modal-header"><h5 class="modal-title">Terminate Contract</h5><button class="btn-close" data-bs-dismiss="modal" type="button"></button></div>
        <div class="modal-body">
          <p class="text-muted">This officially ends <strong id="tc_name"></strong>'s lease for Room <span id="tc_room"></span>. Their payment and maintenance history is kept.</p>
          <div class="mb-3">
            <label class="form-label">Reason (optional)</label>
            <textarea class="form-control" name="termination_reason" rows="3" placeholder="e.g. Tenant moved out at the end of the term"></textarea>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="checkout_tenant" id="tc_checkout" value="1" checked>
            <label class="form-check-label" for="tc_checkout">Also check the tenant out and free up their room</label>
            <div class="form-text">Leave this unticked if you're ending the lease only to replace it with a new contract.</div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-sm btn-action-outline w-100"><i class="bi bi-x-octagon"></i> Terminate Contract</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="record_payment">
        <div class="modal-header"><h5 class="modal-title">Record Payment</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?php if (!$activeContracts): ?>
            <p class="text-muted">No active contracts yet — create one first.</p>
          <?php else: ?>
            <label class="form-label">Contract</label>
            <select class="form-select mb-3" name="contract_id" id="payment_contract_select" required>
              <option value="">Choose…</option>
              <?php foreach ($activeContracts as $c): ?>
                <option value="<?= $c['contract_id'] ?>" data-tenant="<?= $c['tenant_id'] ?>" data-rent="<?= $c['monthly_rent'] ?>"><?= clean($c['first_name'] . ' ' . $c['last_name']) ?> — Room <?= clean($c['room_number']) ?><?= $c['contract_status'] === 'Expired' ? ' (expired contract)' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="tenant_id" id="payment_tenant_id">
            <div class="row g-3">
              <div class="col-md-6"><label class="form-label">Amount (₱)</label><input type="number" step="0.01" min="0" class="form-control" name="payment_amount" id="payment_amount" required></div>
              <div class="col-md-6">
                <label class="form-label">For Month</label>
                <select class="form-select" name="payment_for_month" required>
                  <?php foreach (billing_month_options(6, 6) as $monthOption): ?>
                    <option value="<?= clean($monthOption) ?>"<?= $monthOption === current_billing_month() ? ' selected' : '' ?>><?= clean($monthOption) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="mb-1 mt-3">
              <label class="form-label">Method</label>
              <select class="form-select" name="payment_method">
                <option>Cash</option><option>GCash</option><option>Bank Transfer</option><option>PayMaya</option><option>Card</option>
              </select>
            </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><?php if ($activeContracts): ?><button class="btn btn-sm btn-action-primary">Record Payment</button><?php endif; ?></div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = "<script>
document.getElementById('contract_tenant_select')?.addEventListener('change', function() {
  const rate = this.selectedOptions[0]?.dataset.rate || '';
  document.getElementById('contract_rent').value = rate;
});
document.getElementById('payment_contract_select')?.addEventListener('change', function() {
  document.getElementById('payment_tenant_id').value = this.selectedOptions[0]?.dataset.tenant || '';
  document.getElementById('payment_amount').value = this.selectedOptions[0]?.dataset.rent || '';
});

// ---- Renew / Terminate modals -----------------------------------------
// The renewal date always counts forward from whichever is later: the
// old end date or today. Renewing a lease that lapsed months ago should
// give a term that ends in the future, not one already in the past.
let renewBaseDate = null;

document.getElementById('renewContractModal')?.addEventListener('show.bs.modal', function (e) {
  const btn = e.relatedTarget;
  if (!btn) return;
  document.getElementById('rc_contract_id').value = btn.dataset.id;
  document.getElementById('rc_name').textContent = btn.dataset.name;
  document.getElementById('rc_room').textContent = btn.dataset.room;
  document.getElementById('rc_current_end').value = btn.dataset.end;
  document.getElementById('rc_rent').value = btn.dataset.rent;
  const newEnd = document.getElementById('rc_new_end');
  newEnd.min = btn.dataset.min;
  newEnd.value = btn.dataset.suggested;
  renewBaseDate = btn.dataset.base;
});

document.querySelectorAll('.quick-term-buttons button').forEach(function (btn) {
  btn.addEventListener('click', function () {
    if (!renewBaseDate) return;
    const base = new Date(renewBaseDate + 'T00:00:00');
    const day = base.getDate();
    base.setMonth(base.getMonth() + parseInt(btn.dataset.months, 10));
    // setMonth() rolls a 31st into the next month when the target is
    // shorter (Jan 31 + 1 month => Mar 3). Pull it back to month-end.
    if (base.getDate() !== day) { base.setDate(0); }
    document.getElementById('rc_new_end').value = base.toISOString().slice(0, 10);
  });
});

document.getElementById('terminateContractModal')?.addEventListener('show.bs.modal', function (e) {
  const btn = e.relatedTarget;
  if (!btn) return;
  document.getElementById('tc_contract_id').value = btn.dataset.id;
  document.getElementById('tc_name').textContent = btn.dataset.name;
  document.getElementById('tc_room').textContent = btn.dataset.room;
});
</script>";
include __DIR__ . '/../includes/footer.php';
?>
