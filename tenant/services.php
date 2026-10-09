<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

if (!$tenant || $tenant['approval_status'] !== 'Approved') {
    redirect('/tenant/dashboard.php');
}

refresh_contract_statuses($db);

$room = null;
if ($tenant['room_id']) {
    $stmt = $db->prepare('SELECT * FROM dorm_rooms WHERE room_id = ?');
    $stmt->execute([$tenant['room_id']]);
    $room = $stmt->fetch();
}

// Prefer the lease they're actually living under; fall back to the most
// recent one of any status so a terminated contract still shows here
// instead of the page going blank.
$contract = null;
if ($room) {
    $contract = tenant_current_contract($db, (int) $tenant['tenant_id']);
    if (!$contract) {
        $stmt = $db->prepare("SELECT * FROM contracts WHERE tenant_id = ? ORDER BY contract_end DESC LIMIT 1");
        $stmt->execute([$tenant['tenant_id']]);
        $contract = $stmt->fetch() ?: null;
    }
}

// ---- Tenant-side "continue my contract" request ----------------------
// The tenant can't change their own lease dates — that stays an admin
// decision — but they can flag that they want to carry on, which shows
// up against their contract in Payment & Contract Management.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (($_POST['action'] ?? '') === 'request_renewal') {
        if (!$contract || $contract['contract_status'] === 'Terminated') {
            flash('error', 'There\'s no active contract to renew. Please contact the dorm office.');
        } elseif (!empty($contract['renewal_requested_at'])) {
            flash('error', 'You\'ve already requested a renewal — the office will get back to you.');
        } else {
            $db->prepare("UPDATE contracts SET renewal_requested_at = NOW() WHERE contract_id = ? AND contract_status <> 'Terminated'")
               ->execute([$contract['contract_id']]);
            flash('success', 'Renewal requested. The dorm office will review it and set your new contract end date.');

            $tenantName = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
            $subject = 'Contract renewal requested by ' . $tenantName;
            $message = $tenantName . ' (Room ' . $room['room_number'] . ') has asked to continue their contract, which '
                     . (contract_is_expired($contract) ? 'expired on ' : 'ends on ')
                     . date('F j, Y', strtotime($contract['contract_end']))
                     . '. Open Payment & Contract Management to renew or terminate it.';

            foreach ($db->query("SELECT first_name, email FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll() as $admin) {
                send_email_alert($admin['email'], $admin['first_name'], $subject, email_template('Renewal requested', $message));
            }
        }
        redirect('/tenant/services.php');
    }
}

$pageTitle = 'My Room & Contract';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/page_header.php';
?>
<?php render_page_header('bi-door-open-fill', 'My Room & Contract', 'View your room details and contract terms.', null, 'Tenant Portal', '/tenant/dashboard.php'); ?>

<?php if (!$room): ?>
  <div class="alert alert-info">No room has been assigned to you yet.</div>
<?php else: ?>
<div class="row g-4">
  <div class="col-md-5">
    <div class="room-detail-card">
      <div class="room-detail-header">
        <div class="room-detail-heading">
          <h2 class="text-white">Room <?= clean($room['room_number']) ?></h2>
          <div class="room-detail-type text-white"><?= clean($room['room_type']) ?> · Floor <?= (int) $room['floor_number'] ?></div>
        </div>
        <span class="room-icon text-white" aria-hidden="true"><i class="bi bi-house-door-fill"></i></span>
      </div>
      <div class="room-detail-stats">
        <div class="room-detail-stat"><span class="room-detail-label text-white">Capacity</span><strong class="text-white"><?= (int) $room['capacity'] ?> tenant(s)</strong></div>
        <div class="room-detail-stat"><span class="room-detail-label text-white">Monthly Rate</span><strong class="text-white"><?= peso($room['monthly_rate']) ?></strong></div>
        <div class="room-detail-stat"><span class="room-detail-label text-white">Move-in</span><strong class="text-white"><?= $tenant['checkin_date'] ? clean(date('M j, Y', strtotime($tenant['checkin_date']))) : 'Not checked in yet' ?></strong></div>
        <div class="room-detail-stat"><span class="room-detail-label text-white">Status</span><strong class="text-white"><?= clean($tenant['status']) ?></strong></div>
      </div>
    </div>
  </div>
  <div class="col-md-7">
    <div class="panel">
      <div class="panel-header"><h2>Contract Information</h2></div>
      <?php if (!$contract): ?>
        <p class="text-muted py-3">No contract has been created for you yet — check with the admin office.</p>
      <?php else:
        $daysLeft   = days_until($contract['contract_end']);
        $isExpired  = contract_is_expired($contract);
        $isClosed   = $contract['contract_status'] === 'Terminated';
        $requested  = !empty($contract['renewal_requested_at']);
      ?>
        <div class="detail-row"><span>Contract Period</span><strong><?= clean(date('M j, Y', strtotime($contract['contract_start']))) ?> – <?= clean(date('M j, Y', strtotime($contract['contract_end']))) ?></strong></div>
        <div class="detail-row"><span>Monthly Rent</span><strong><?= peso($contract['monthly_rent']) ?></strong></div>
        <div class="detail-row"><span>Security Deposit</span><strong><?= peso($contract['security_deposit']) ?></strong></div>
        <div class="detail-row"><span>Contract Status</span><span class="badge badge-<?= status_badge_class($contract['contract_status']) ?>"><?= clean($contract['contract_status']) ?></span></div>
        <div class="detail-row">
          <span><?= $isExpired || $isClosed ? 'Ended' : 'Days Remaining' ?></span>
          <strong class="<?= $isExpired || $daysLeft <= 14 ? 'text-danger' : '' ?>">
            <?= $isExpired || $isClosed ? abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' ago' : $daysLeft . ' days' ?>
          </strong>
        </div>
        <?php if ((int) ($contract['renewal_count'] ?? 0) > 0): ?>
          <div class="detail-row"><span>Renewals</span><strong><?= (int) $contract['renewal_count'] ?>× <?= $contract['last_renewed_at'] ? '(last ' . clean(date('M j, Y', strtotime($contract['last_renewed_at']))) . ')' : '' ?></strong></div>
        <?php endif; ?>

        <?php if ($isClosed): ?>
          <div class="mt-3 p-3 rounded-3" style="border:1px solid var(--border);">
            <strong>Contract ended</strong>
            <p class="text-muted small mb-0">
              This contract was terminated by the dorm office<?= !empty($contract['terminated_at']) ? ' on ' . clean(date('F j, Y', strtotime($contract['terminated_at']))) : '' ?>.
              <?= !empty($contract['termination_reason']) ? 'Reason given: ' . clean($contract['termination_reason']) : '' ?>
            </p>
          </div>
        <?php elseif ($isExpired || $daysLeft <= 30): ?>
          <div class="mt-3 p-3 rounded-3" style="border:1px solid <?= $isExpired ? 'var(--red-text)' : 'var(--border)' ?>;">
            <strong><i class="bi <?= $isExpired ? 'bi-exclamation-triangle-fill' : 'bi-calendar2-warning' ?> me-1" aria-hidden="true"></i><?= $isExpired ? 'Contract Expired' : 'Contract Expiring Soon' ?></strong>
            <p class="text-muted small mb-2">
              <?php if ($isExpired): ?>
                Your contract ended on <?= clean(date('F j, Y', strtotime($contract['contract_end']))) ?>. Ask the office to continue it, or visit them to arrange moving out.
              <?php else: ?>
                Your contract expires in <?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?>. Request a renewal here, or visit the office.
              <?php endif; ?>
            </p>
            <?php if ($requested): ?>
              <span class="badge badge-info"><i class="bi bi-check-lg"></i> Renewal requested <?= clean(date('M j, Y', strtotime($contract['renewal_requested_at']))) ?></span>
              <div class="text-muted small mt-1">The dorm office will set your new end date and you'll be notified.</div>
            <?php else: ?>
              <form method="post" onsubmit="return confirm('Send a renewal request to the dorm office?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="request_renewal">
                <button class="btn btn-sm btn-action-primary"><i class="bi bi-arrow-repeat"></i> Request Renewal</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($contract['contract_file']): ?>
          <a href="<?= BASE_URL . '/' . clean($contract['contract_file']) ?>" target="_blank" class="btn btn-action-light mt-3"><i class="bi bi-file-earmark-text"></i> View Contract File</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
