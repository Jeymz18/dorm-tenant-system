<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();
$selfPath = '/admin/tenant-status.php';
require __DIR__ . '/../includes/tenant_action_handler.php';
$statusLabels = ['Evicted' => 'Lease Terminated'];
$search = str_input($_GET, 'q');
$statusFilter = str_input($_GET, 'status', 'all');
$statusOptions = ['all', 'Active', 'Pending', 'Checked Out', 'Evicted'];
if (!in_array($statusFilter, $statusOptions, true)) {
  $statusFilter = 'all';
}

// Stat counts reflect ALL approved tenants, not just the current page.
$counts = ['Active' => 0, 'Pending' => 0, 'Evicted' => 0, 'Checked Out' => 0];
$countRows = $db->query("SELECT status, COUNT(*) c FROM tenants
  WHERE approval_status = 'Approved'
    AND tenant_id NOT IN (SELECT tenant_id FROM dismissed_records WHERE page = 'status')
  GROUP BY status")->fetchAll();
foreach ($countRows as $row) { $counts[$row['status']] = (int) $row['c']; }

$where = [
  "t.approval_status = 'Approved'",
  "t.tenant_id NOT IN (SELECT tenant_id FROM dismissed_records WHERE page = 'status')",
];
$params = [];
if ($statusFilter !== 'all') {
  $where[] = 't.status = ?';
  $params[] = $statusFilter;
}
if ($search !== '') {
  $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR r.room_number LIKE ?)';
  $like = '%' . $search . '%';
  array_push($params, $like, $like, $like, $like);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$result = paginate(
    $db,
    "SELECT t.*, u.first_name, u.last_name, r.room_number,
            (SELECT MAX(payment_date) FROM payments p WHERE p.tenant_id = t.tenant_id AND p.payment_status='Paid') AS last_payment
     FROM tenants t
     JOIN users u ON u.user_id = t.user_id
     LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
   $whereSql
     ORDER BY FIELD(t.status,'Active','Pending','Evicted','Checked Out'), u.first_name",
  "SELECT COUNT(*) c FROM tenants t
   JOIN users u ON u.user_id = t.user_id
   LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
   $whereSql",
  $params,
  10
);
$allTenants = $result['rows'];

$pageTitle = 'Track Tenant Status';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/module_tabs.php';
require_once __DIR__ . '/../includes/page_header.php';
render_page_header(
    'bi-people-fill',
    'Tenant Status',
    'Review applications, track tenant status, and manage check-in/check-out.',
    '<a href="' . BASE_URL . '/admin/users.php" class="btn btn-top-action"><i class="bi bi-plus-lg"></i> New Application</a>'
);
render_module_tabs([
  ['key' => 'registration', 'label' => 'Registration & Approval', 'href' => '/admin/tenants.php'],
  ['key' => 'status', 'label' => 'Track Status', 'href' => '/admin/tenant-status.php'],
  ['key' => 'checkin', 'label' => 'Check-in / Check-out', 'href' => '/admin/checkinout.php'],
  ['key' => 'reservations', 'label' => 'Reservations', 'href' => '/admin/reservations.php'],
], 'status'); ?>

<div class="stat-grid stat-grid-4">
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Active</div><div class="stat-value text-success"><?= $counts['Active'] ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-check-lg"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Pending Check-in</div><div class="stat-value"><?= $counts['Pending'] ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-clock-fill"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Checked Out</div><div class="stat-value"><?= $counts['Checked Out'] ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-box-arrow-in-right"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Lease Terminated</div><div class="stat-value text-danger"><?= $counts['Evicted'] ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-slash-circle"></i></div></div>
</div>

<div class="panel mt-2">
  <div class="panel-header">
    <h2>All Tenants</h2>
  </div>
  <div class="filter-toolbar">
    <form class="search-box" method="get">
      <i class="bi bi-search"></i>
      <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= clean($statusFilter) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= clean($search) ?>" placeholder="Search tenant, email, room…" class="form-control form-control-sm">
    </form>
    <div class="filter-pills">
      <?php foreach ($statusOptions as $key):
          $label = $key === 'all' ? 'All' : ($statusLabels[$key] ?? $key);
          $count = $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0);
          $query = http_build_query(array_filter(['status' => $key === 'all' ? null : $key, 'q' => $search ?: null]));
      ?>
        <a href="?<?= clean($query) ?>" class="filter-pill <?= $statusFilter === $key ? 'active' : '' ?>"><?= clean($label) ?> <span class="pill-count"><?= $count ?></span></a>
      <?php endforeach; ?>
    </div>
    <span class="filter-result-count"><?= $result['total'] ?> result<?= $result['total'] === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-responsive">
    <table class="table app-table tenant-management-table align-middle">
      <thead><tr><th>Tenant</th><th>Room</th><th>Status</th><th>Contract Period</th><th>Details</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$allTenants): ?><tr><td colspan="6" class="text-center text-muted py-4">No approved tenants yet.</td></tr><?php endif; ?>
      <?php foreach ($allTenants as $t):
        if ($t['status'] === 'Active' && $t['last_payment']) {
            $details = 'Last payment: ' . date('n/j/Y', strtotime($t['last_payment']));
        } elseif ($t['status'] === 'Active') {
            $details = 'Awaiting first payment';
        } elseif ($t['status'] === 'Pending') {
            $details = $t['room_id'] ? 'Ready for check-in' : 'Awaiting room assignment';
        } elseif ($t['status'] === 'Checked Out') {
            $details = 'Moved out ' . ($t['checkout_date'] ? date('n/j/Y', strtotime($t['checkout_date'])) : '');
        } else {
            $details = 'Tenancy ended';
        }
      ?>
        <tr>
          <td>
            <div class="cell-person">
              <div class="applicant-avatar"><i class="bi bi-person-fill" aria-hidden="true"></i></div>
              <div><?= clean($t['first_name'] . ' ' . $t['last_name']) ?></div>
            </div>
          </td>
          <td><?= $t['room_number'] ? '<i class="bi bi-house-door-fill"></i> Room ' . clean($t['room_number']) : '<span class="text-muted">Unassigned</span>' ?></td>
          <td><span class="badge badge-<?= status_badge_class($t['status']) ?>"><?= clean($statusLabels[$t['status']] ?? $t['status']) ?></span></td>
          <td class="text-muted small"><i class="bi bi-calendar-event"></i> <?= $t['checkin_date'] ? clean(date('n/j/Y', strtotime($t['checkin_date']))) : '—' ?><?= $t['checkout_date'] ? ' – ' . clean(date('n/j/Y', strtotime($t['checkout_date']))) : '' ?></td>
          <td class="text-muted small"><?= clean($details) ?></td>
          <td class="text-end">
            <?php if ($t['status'] === 'Pending' && $t['room_id']): ?>
              <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="checkin"><input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>"><button class="btn btn-sm btn-action-primary d-inline-flex align-items-center justify-content-center">Check In</button></form>
            <?php elseif ($t['status'] === 'Active'): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Check out this tenant?');"><?= csrf_field() ?><input type="hidden" name="action" value="checkout"><input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>"><button class="btn btn-sm btn-action-outline d-inline-flex align-items-center justify-content-center">Check Out</button></form>
              <form method="post" class="d-inline" onsubmit="return confirm('Terminate this lease? This frees up the tenant\'s room.');"><?= csrf_field() ?><input type="hidden" name="action" value="evict"><input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>"><button class="btn btn-sm btn-action-reject"><i class="bi bi-file-earmark-x" aria-hidden="true"></i> Terminate Lease</button></form>
            <?php else: ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($result['page'], $result['totalPages']) ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
