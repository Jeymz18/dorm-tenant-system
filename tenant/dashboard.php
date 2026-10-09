<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

// ---- Not yet approved / no room -------------------------------------
if (!$tenant || $tenant['approval_status'] !== 'Approved') {
    $pageTitle = 'Home';
    include __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/page_header.php';
    render_page_header('bi-house-door-fill', 'Home', 'Welcome to your dorm portal.', null, 'Tenant Portal', '/tenant/dashboard.php');
    if ($tenant && $tenant['approval_status'] === 'Rejected') {
        ?>
        <div class="pending-screen">
          <div class="pending-icon"><i class="bi bi-x-lg"></i></div>
          <h2>Your application wasn't approved</h2>
          <p class="text-muted">
            <?php if ($tenant['rejection_reason']): ?>
              Reason given: <?= clean($tenant['rejection_reason']) ?>
            <?php else: ?>
              No specific reason was given.
            <?php endif; ?>
          </p>
          <p class="text-muted">If you have questions or believe this was a mistake, please contact the dorm office.</p>
        </div>
        <?php
    } else {
        ?>
        <div class="pending-screen">
          <div class="icon-wrapper icon-circle pending-icon"><i class="bi bi-hourglass-split"></i></div>
          <h2>Your application is under review</h2>
          <p class="text-muted">An admin needs to approve your application before your dashboard unlocks. Check back soon — we'll email you once it's approved.</p>
        </div>
        <?php
    }
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$room = null;
if ($tenant['room_id']) {
    $stmt = $db->prepare('SELECT * FROM dorm_rooms WHERE room_id = ?');
    $stmt->execute([$tenant['room_id']]);
    $room = $stmt->fetch();
}

// Keep contract statuses current before reading them, so a lease that
// ran out shows as expired here the same day it does in the admin panel.
refresh_contract_statuses($db);

$contract = $room ? tenant_current_contract($db, (int) $tenant['tenant_id']) : null;
$contractExpired = contract_is_expired($contract);

// Confirm any GCash payment that's still waiting, straight from
// PayMongo. This is what makes the rent status below flip to Paid on
// its own — the tenant never has to mark anything themselves.
sync_pending_gcash_payments($db, (int) $tenant['tenant_id']);

// Where this tenant stands on THIS month's rent.
$rent = tenant_rent_status($db, (int) $tenant['tenant_id'], $contract);

$recentPayments = $db->prepare('SELECT * FROM payments WHERE tenant_id = ? ORDER BY COALESCE(payment_date, due_date) DESC LIMIT 5');
$recentPayments->execute([$tenant['tenant_id']]);
$recentPayments = $recentPayments->fetchAll();

$notifStmt = $db->prepare("
    SELECT n.* FROM notifications n
    WHERE (n.target_type = 'all'
       OR (n.target_type = 'tenant' AND n.target_value = ?)
       OR (n.target_type = 'room' AND FIND_IN_SET(?, REPLACE(n.target_value, ' ', ''))))
      AND n.date_sent >= (SELECT date_created FROM users WHERE user_id = ?)
    ORDER BY n.date_sent DESC LIMIT 4
");
$notifStmt->execute([$tenant['tenant_id'], $room['room_number'] ?? '__none__', current_user_id()]);
$announcements = $notifStmt->fetchAll();
$typeTint = ['Announcement' => '', 'Payment Reminder' => 'tint-amber', 'Contract Expiry Alert' => 'tint-blue'];

// Any OTHER unsettled month — arrears the rent card above doesn't
// already cover. Without this filter the same month would be announced
// twice, once in the card and once in the alert.
$nextDue = null;
foreach ($recentPayments as $p) {
    if ($p['payment_status'] === 'Paid' || $p['payment_status'] === 'Failed') { continue; }
    if (strcasecmp(trim($p['payment_for_month'] ?? ''), $rent['month']) === 0) { continue; }
    $nextDue = $p;
    break;
}

$pageTitle = 'Home';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/page_header.php';
?>
<?php render_page_header('bi-house-door-fill', 'Home', 'Welcome, ' . $_SESSION['first_name'] . '. Here is your dorm overview.', null, 'Tenant Portal', '/tenant/dashboard.php'); ?>

<?php if ($room): ?>
<?php
// ---- This month's rent, at a glance --------------------------------
// Driven entirely by the payments table: once a payment for this month
// lands as Paid (GCash webhook, the status check above, or an admin
// recording a cash payment) this card turns green on its own.
$rentCardClass = ['Paid' => 'is-paid', 'Pending' => 'is-pending', 'Overdue' => 'is-due', 'Due' => 'is-due', 'None' => 'is-none'][$rent['state']];
$rentPaid = $rent['state'] === 'Paid';
?>
<div class="rent-status-card <?= $rentCardClass ?> mb-4">
  <div>
    <div class="rent-status-label"><?= $rentPaid ? 'Rent' : 'Rent Due' ?> · <?= clean($rent['month']) ?></div>
    <div class="rent-status-amount"><?= peso($rent['amount']) ?></div>
    <div class="rent-status-note">
      <?php if ($rent['state'] === 'Paid'): ?>
        <i class="bi bi-check-circle-fill"></i> Settled<?= !empty($rent['payment']['payment_date']) ? ' on ' . clean(date('M j, Y', strtotime($rent['payment']['payment_date']))) : '' ?><?= !empty($rent['payment']['payment_method']) ? ' · ' . clean($rent['payment']['payment_method']) : '' ?>. Nothing more to pay this month.
      <?php elseif ($rent['state'] === 'Pending'): ?>
        <i class="bi bi-hourglass-split"></i> We're confirming your GCash payment — this updates to Paid on its own, no need to refresh.
      <?php elseif ($rent['state'] === 'Overdue'): ?>
        <i class="bi bi-exclamation-triangle-fill"></i> This month's rent is past its due date.
      <?php elseif ($rent['state'] === 'None'): ?>
        You don't have an active contract, so no rent is being billed right now.
      <?php else: ?>
        <i class="bi bi-info-circle"></i> This month's rent hasn't been paid yet.
      <?php endif; ?>
    </div>
  </div>
  <div class="rent-status-side">
    <span class="rent-status-pill"><span class="rent-status-dot"></span><?= clean($rent['label']) ?></span>
    <?php if (in_array($rent['state'], ['Due', 'Overdue'], true)): ?>
      <a href="<?= BASE_URL ?>/tenant/payments.php" class="btn btn-sm btn-action-primary"><i class="bi bi-phone"></i> Pay Now</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($contract && days_until($contract['contract_end']) <= 14 && !$contractExpired): ?>
  <div class="alert alert-warning mt-3"><i class="bi bi-exclamation-triangle-fill"></i> Your contract expires in <?= days_until($contract['contract_end']) ?> day(s). You can <a href="<?= BASE_URL ?>/tenant/services.php">request a renewal</a> or visit the office.</div>
<?php endif; ?>
<?php if ($nextDue): ?>
  <div class="alert alert-<?= $nextDue['payment_status'] === 'Overdue' ? 'danger' : 'warning' ?> mt-3">
    <i class="bi bi-credit-card-fill"></i> You also have a <?= strtolower($nextDue['payment_status']) ?> payment of <?= peso($nextDue['payment_amount']) ?><?= $nextDue['payment_for_month'] ? ' for ' . clean($nextDue['payment_for_month']) : '' ?>.
  </div>
<?php endif; ?>
<?php else: ?>
  <div class="alert alert-info mt-3">You're approved! A room hasn't been assigned to you yet — the admin office will notify you once it is.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <a href="<?= BASE_URL ?>/tenant/services.php" class="card border-0 shadow-sm rounded-4 p-3 text-decoration-none hover-lift h-100 d-flex flex-row align-items-center gap-3">
      <div class="dashboard-action-icon bg-maroon-subtle text-maroon rounded-3 d-flex align-items-center justify-content-center">
        <i class="bi bi-door-open-fill fs-5"></i>
      </div>
      <div>
        <h6 class="fw-bold text-dark mb-0">My Room &amp; Contract</h6>
        <small class="text-muted">View contract terms &amp; room details</small>
      </div>
    </a>
  </div>

  <div class="col-md-6">
    <a href="<?= BASE_URL ?>/tenant/maintenance.php" class="card border-0 shadow-sm rounded-4 p-3 text-decoration-none hover-lift h-100 d-flex flex-row align-items-center gap-3">
      <div class="dashboard-action-icon bg-maroon-subtle text-maroon rounded-3 d-flex align-items-center justify-content-center">
        <i class="bi bi-tools fs-5"></i>
      </div>
      <div>
        <h6 class="fw-bold text-dark mb-0">Request Repair</h6>
        <small class="text-muted">Report an issue or maintenance request</small>
      </div>
    </a>
  </div>
</div>

<div class="row g-4 mt-1">
  <div class="col-md-6">
    <div class="panel">
      <div class="panel-header"><h2>Recent Payments</h2></div>
      <?php if (!$recentPayments): ?><p class="text-muted py-3">No payments yet.</p><?php endif; ?>
      <?php foreach ($recentPayments as $p): ?>
        <div class="list-row">
          <div><?= clean($p['payment_for_month'] ?: date('M j, Y', strtotime($p['due_date'] ?? $p['created_at']))) ?></div>
          <div class="text-end"><?= peso($p['payment_amount']) ?><br><span class="badge badge-<?= status_badge_class($p['payment_status']) ?>"><?= clean($p['payment_status']) ?></span></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="col-md-6">
    <div class="panel">
      <div class="panel-header"><h2>Latest Announcements</h2></div>
      <?php if (!$announcements): ?><p class="text-muted py-3">No announcements yet.</p><?php endif; ?>
      <?php foreach ($announcements as $n): ?>
        <div class="notification-item <?= $typeTint[$n['type']] ?? '' ?> align-items-start">
          <div class="notification-icon"><i class="bi bi-bell-fill"></i></div>
          <div class="flex-grow-1"><strong><?= clean($n['subject']) ?></strong><div class="text-muted small"><?= clean($n['message']) ?></div></div>
          <div class="text-muted small text-nowrap ms-2"><?= clean(date('M j', strtotime($n['date_sent']))) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php
// While a GCash payment is still being confirmed, quietly re-check a
// few times so the card flips to Paid by itself. Capped (and tracked
// per browser tab) so it can never turn into an endless reload loop —
// after that the tenant just reloads whenever they like.
if ($rent['state'] === 'Pending') {
    $extraScripts = "<script>
(function () {
  var KEY = 'rentPollCount';
  var MAX = 8;      // ~4 minutes of checking, then stop
  var EVERY = 30000;
  var count = parseInt(sessionStorage.getItem(KEY) || '0', 10);
  if (count >= MAX) { return; }
  setTimeout(function () {
    sessionStorage.setItem(KEY, String(count + 1));
    location.reload();
  }, EVERY);
})();
</script>";
} else {
    // Settled (or never started) — reset the counter for next time.
    $extraScripts = "<script>try { sessionStorage.removeItem('rentPollCount'); } catch (e) {}</script>";
}
include __DIR__ . '/../includes/footer.php';
?>
