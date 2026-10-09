<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();
$selfPath = '/admin/tenants.php';
require __DIR__ . '/../includes/tenant_action_handler.php';

$search = str_input($_GET, 'q');
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$normalizedStatus = strtolower($statusFilter);
if ($normalizedStatus === 'pending' || $normalizedStatus === 'pending ') {
    $statusFilter = 'Pending';
} elseif ($normalizedStatus === 'approved') {
    $statusFilter = 'Approved';
} elseif ($normalizedStatus === 'rejected' || $normalizedStatus === 'declined') {
    $statusFilter = 'Rejected';
} elseif ($normalizedStatus === 'all' || $statusFilter === '') {
    $statusFilter = 'all';
} else {
    $statusFilter = 'all';
}
if (!in_array($statusFilter, ['all', 'Pending', 'Approved', 'Rejected'], true)) {
    $statusFilter = 'all';
}

$where = ["t.tenant_id NOT IN (SELECT tenant_id FROM dismissed_records WHERE page = 'approval')"];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'COALESCE(t.approval_status, \'Pending\') = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR r.room_number LIKE ?)';
    $like = "%$search%";
    array_push($params, $like, $like, $like);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

// Function to export tenants to CSV
function exportTenantsToCSV($tenants) {
  while (ob_get_level()) {
    ob_end_clean();
  }
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="tenants_' . date('Y-m-d_H-i') . '.csv"');
    $output = fopen('php://output', 'w');
  fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Tenant ID', 'First Name', 'Last Name', 'Room Number', 'Approval Status']);
    foreach ($tenants as $tenant) {
        fputcsv($output, [
            $tenant['tenant_id'],
            $tenant['first_name'],
            $tenant['last_name'],
            $tenant['room_number'] ?? 'No room assigned',
            $tenant['approval_status']
        ]);
    }
    fclose($output);
    exit();
}

// Check if export is requested — exports the currently filtered/searched set
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $db->prepare("
        SELECT t.tenant_id, u.first_name, u.last_name, r.room_number,
               COALESCE(t.approval_status, 'Pending') AS approval_status
        FROM tenants t
        JOIN users u ON u.user_id = t.user_id
        LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
        $whereSql
        ORDER BY t.date_registered DESC
    ");
    $exportStmt->execute($params);
    exportTenantsToCSV($exportStmt->fetchAll());
}

$statusCounts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
foreach ($db->query("
    SELECT COALESCE(t.approval_status, 'Pending') AS approval_status, COUNT(*) c
    FROM tenants t
    WHERE t.tenant_id NOT IN (SELECT tenant_id FROM dismissed_records WHERE page = 'approval')
    GROUP BY t.approval_status
") as $row) {
    $statusCounts[$row['approval_status']] = (int) $row['c'];
}
$totalCount = array_sum($statusCounts);

$result = paginate(
    $db,
    "SELECT t.tenant_id, u.first_name, u.last_name, u.email, u.phone, t.tenant_type,
            t.room_id, r.room_number, COALESCE(t.approval_status, 'Pending') AS approval_status,
            t.rejection_reason, t.date_registered
     FROM tenants t
     JOIN users u ON u.user_id = t.user_id
     LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
     $whereSql
      ORDER BY FIELD(COALESCE(t.approval_status, 'Pending'),'Pending','Approved','Rejected'), t.date_registered DESC",
    "SELECT COUNT(*) c FROM tenants t JOIN users u ON u.user_id = t.user_id LEFT JOIN dorm_rooms r ON r.room_id = t.room_id $whereSql",
    $params,
    10
);
$applications = $result['rows'];

$pageTitle = 'Tenant Registration/Approval';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/module_tabs.php';
require_once __DIR__ . '/../includes/page_header.php';
render_page_header(
    'bi-people-fill',
    'Tenant Registration',
    'Review applications, track tenant status, and manage check-in/check-out.',
    '<a href="' . BASE_URL . '/admin/users.php" class="btn btn-top-action"><i class="bi bi-plus-lg"></i> New Application</a>'
);
render_module_tabs([
  ['key' => 'registration', 'label' => 'Registration & Approval', 'href' => '/admin/tenants.php'],
  ['key' => 'status', 'label' => 'Track Status', 'href' => '/admin/tenant-status.php'],
  ['key' => 'checkin', 'label' => 'Check-in / Check-out', 'href' => '/admin/checkinout.php'],
  ['key' => 'reservations', 'label' => 'Reservations', 'href' => '/admin/reservations.php'],
], 'registration');

$statusPills = [
    'all'      => 'All',
    'Pending'  => 'Pending',
    'Approved' => 'Approved',
    'Rejected' => 'Rejected',
];
?>
<div class="panel">
  <div class="filter-toolbar">
    <form class="search-box" method="get">
      <i class="bi bi-search"></i>
      <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= clean($statusFilter) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= clean($search) ?>" placeholder="Search applicant, room…" class="form-control form-control-sm">
    </form>
    <div class="filter-pills">
      <?php foreach ($statusPills as $key => $label):
          $count = $key === 'all' ? $totalCount : $statusCounts[$key];
          $qs = http_build_query(array_filter(['status' => $key === 'all' ? null : $key, 'q' => $search ?: null]));
      ?>
        <a href="?<?= $qs ?>" class="filter-pill <?= $statusFilter === $key ? 'active' : '' ?>"><?= clean($label) ?> <span class="pill-count"><?= $count ?></span></a>
      <?php endforeach; ?>
    </div>
    <span class="filter-result-count"><?= $result['total'] ?> result<?= $result['total'] === 1 ? '' : 's' ?></span>
  </div>

  <?php if (!$applications): ?>
    <div class="empty-state-card text-center p-5">
      <i class="bi bi-person-check fs-1 text-muted"></i>
      <h5 class="mt-3 fw-bold text-dark">No applications found</h5>
      <p class="text-muted small">Try a different search or filter.</p>
    </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table app-table tenant-management-table align-middle">
      <thead><tr><th>Applicant</th><th>Contact</th><th>Room</th><th>Applied</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($applications as $p): ?>
        <?php $status = strtolower(trim((string) ($p['approval_status'] ?? 'Pending'))); ?>
        <tr>
          <td>
            <div class="cell-person">
              <div class="applicant-avatar"><i class="bi bi-person-fill"></i></div>
              <div>
                <?= clean($p['first_name'] . ' ' . $p['last_name']) ?>
                <div class="sub"><?= clean($p['tenant_type']) ?></div>
                <?php if ($status === 'rejected' && $p['rejection_reason']): ?>
                  <div class="sub">Reason: <?= clean($p['rejection_reason']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td class="text-muted small"><i class="bi bi-envelope-fill"></i> <?= clean($p['email']) ?><?= $p['phone'] ? '<br><i class="bi bi-telephone-fill"></i> ' . clean($p['phone']) : '' ?></td>
          <td class="small"><?php if ($p['room_number']): ?><i class="bi bi-geo-alt-fill text-muted"></i> Room <?= clean($p['room_number']) ?><?php else: ?><span class="text-muted">Unassigned</span><?php endif; ?></td>
          <td class="text-muted small"><i class="bi bi-calendar-event"></i> <?= clean(date('n/j/Y', strtotime($p['date_registered']))) ?></td>
          <td><span class="badge badge-<?= status_badge_class($p['approval_status']) ?>"><?= clean($p['approval_status']) ?></span></td>
          <td class="text-end">
            <div class="d-flex justify-content-end gap-2 flex-wrap align-items-center">
            <?php if ($status === 'pending'): ?>
              <div class="d-flex gap-2 align-items-center">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="tenant_id" value="<?= $p['tenant_id'] ?>"><button class="btn btn-action-approve"><i class="bi bi-check-lg"></i> Approve</button></form>
                <button type="button" class="btn btn-action-reject" data-bs-toggle="modal" data-bs-target="#rejectModal"
                  data-id="<?= $p['tenant_id'] ?>" data-name="<?= clean($p['first_name']) ?>"><i class="bi bi-x-lg"></i> Reject</button>
              </div>
            <?php elseif ($status === 'approved' && empty($p['room_id'])): ?>
              <a href="<?= BASE_URL ?>/admin/rooms.php?tenant=<?= $p['tenant_id'] ?>&tab=assign#rooms" class="btn btn-sm btn-action-outline"><i class="bi bi-door-open"></i> Assign Room</a>
            <?php elseif ($status === 'approved'): ?>
              <span class="text-muted small">Assigned</span>
            <?php else: ?>
              <span class="text-muted small">Processed</span>
            <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($result['page'], $result['totalPages']) ?>
  <?php endif; ?>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="tenant_id" id="reject_tenant_id">
        <div class="modal-header"><h5 class="modal-title">Reject Application — <span id="reject_tenant_name"></span></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <label class="form-label">Reason <span class="text-muted">(optional, but the applicant will see it)</span></label>
          <textarea class="form-control" name="reason" rows="3" placeholder="e.g. Missing required documents, no rooms matching your request…"></textarea>
        </div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-sm btn-action-outline">Reject Application</button></div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = "<script>
document.getElementById('rejectModal').addEventListener('show.bs.modal', function (e) {
  const btn = e.relatedTarget;
  document.getElementById('reject_tenant_id').value = btn.dataset.id;
  document.getElementById('reject_tenant_name').textContent = btn.dataset.name;
});
</script>";
include __DIR__ . '/../includes/footer.php';
?>
