<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();
$selfPath = '/admin/checkinout.php';
require __DIR__ . '/../includes/tenant_action_handler.php';
$search = str_input($_GET, 'q');
$statusFilter = str_input($_GET, 'status', 'all');
$statusOptions = ['all', 'Active', 'Checked Out'];
if (!in_array($statusFilter, $statusOptions, true)) {
  $statusFilter = 'all';
}

$checkedIn  = (int) $db->query("SELECT COUNT(*) c FROM tenants WHERE status = 'Active'")->fetch()['c'];
$checkedOut = (int) $db->query("SELECT COUNT(*) c FROM tenants WHERE status = 'Checked Out'")->fetch()['c'];
$keysOut    = (int) $db->query("SELECT COUNT(*) c FROM tenants WHERE status = 'Checked Out' AND key_returned = FALSE")->fetch()['c'];

$statusCounts = ['Active' => 0, 'Checked Out' => 0];
$countRows = $db->query("SELECT status, COUNT(*) c FROM tenants
  WHERE status IN ('Active','Checked Out')
    AND tenant_id NOT IN (SELECT tenant_id FROM dismissed_records WHERE page = 'checkinout')
  GROUP BY status")->fetchAll();
foreach ($countRows as $row) {
  $statusCounts[$row['status']] = (int) $row['c'];
}

$where = [
  "t.status IN ('Active','Checked Out')",
  "t.tenant_id NOT IN (SELECT tenant_id FROM dismissed_records WHERE page = 'checkinout')",
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
    "SELECT t.*, u.first_name, u.last_name, r.room_number
     FROM tenants t
     JOIN users u ON u.user_id = t.user_id
     LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
   $whereSql
   ORDER BY FIELD(t.status,'Active','Checked Out'), t.checkin_date DESC",
  "SELECT COUNT(*) c FROM tenants t
   JOIN users u ON u.user_id = t.user_id
   LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
   $whereSql",
  $params,
  10
);
$records = $result['rows'];

$pageTitle = 'Check-in / Check-out Monitor';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/module_tabs.php';
require_once __DIR__ . '/../includes/page_header.php';
render_page_header(
    'bi-box-arrow-in-right',
    'Check-In / Check-Out',
    'Review applications, track tenant status, and manage check-in/check-out.',
    '<a href="' . BASE_URL . '/admin/users.php" class="btn btn-top-action"><i class="bi bi-plus-lg"></i> New Application</a>'
);
render_module_tabs([
  ['key' => 'registration', 'label' => 'Registration & Approval', 'href' => '/admin/tenants.php'],
  ['key' => 'status', 'label' => 'Track Status', 'href' => '/admin/tenant-status.php'],
  ['key' => 'checkin', 'label' => 'Check-in / Check-out', 'href' => '/admin/checkinout.php'],
  ['key' => 'reservations', 'label' => 'Reservations', 'href' => '/admin/reservations.php'],
], 'checkin'); ?>

<div class="stat-grid stat-grid-3">
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Currently Checked In</div><div class="stat-value"><?= $checkedIn ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-box-arrow-in-right"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Checked Out</div><div class="stat-value"><?= $checkedOut ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-box-arrow-left"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Keys Not Returned</div><div class="stat-value text-danger"><?= $keysOut ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-key-fill"></i></div></div>
</div>

<div class="panel mt-2">
  <div class="panel-header">
    <h2>All Records</h2>
  </div>
  <div class="filter-toolbar">
    <form class="search-box" method="get">
      <i class="bi bi-search"></i>
      <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= clean($statusFilter) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= clean($search) ?>" placeholder="Search tenant, email, room…" class="form-control form-control-sm">
    </form>
    <div class="filter-pills">
      <?php foreach ($statusOptions as $key):
          $label = $key === 'all' ? 'All' : ($key === 'Active' ? 'Checked In' : $key);
          $count = $key === 'all' ? array_sum($statusCounts) : $statusCounts[$key];
          $query = http_build_query(array_filter(['status' => $key === 'all' ? null : $key, 'q' => $search ?: null]));
      ?>
        <a href="?<?= clean($query) ?>" class="filter-pill <?= $statusFilter === $key ? 'active' : '' ?>"><?= clean($label) ?> <span class="pill-count"><?= $count ?></span></a>
      <?php endforeach; ?>
    </div>
    <span class="filter-result-count"><?= $result['total'] ?> result<?= $result['total'] === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-responsive">
    <table class="table app-table tenant-management-table align-middle">
      <thead><tr><th>Tenant</th><th>Room</th><th>Check-in Date</th><th>Check-out Date</th><th>Key Return</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$records): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= $statusFilter === 'all' && $search === '' ? 'No check-in records yet.' : 'No records match this filter.' ?></td></tr><?php endif; ?>
      <?php foreach ($records as $t): ?>
        <tr>
          <td>
            <div class="cell-person">
              <div class="applicant-avatar"><i class="bi bi-person-fill" aria-hidden="true"></i></div>
              <div><?= clean($t['first_name'] . ' ' . $t['last_name']) ?></div>
            </div>
          </td>
          <td><?= $t['room_number'] ? '<i class="bi bi-house-door-fill"></i> Room ' . clean($t['room_number']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-muted small"><i class="bi bi-calendar-event"></i> <?= $t['checkin_date'] ? clean(date('n/j/Y', strtotime($t['checkin_date']))) : '—' ?></td>
          <td class="text-muted small"><?= $t['checkout_date'] ? '<i class="bi bi-calendar-event"></i> ' . clean(date('n/j/Y', strtotime($t['checkout_date']))) : '—' ?></td>
          <td>
            <?php if ($t['status'] !== 'Checked Out'): ?>
              <span class="text-muted">—</span>
            <?php elseif ($t['key_returned']): ?>
              <span class="badge badge-success">Returned</span>
            <?php else: ?>
              <span class="badge badge-danger">Not Returned</span>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-<?= $t['status'] === 'Active' ? 'info' : 'secondary' ?>"><?= $t['status'] === 'Active' ? 'Checked In' : 'Checked Out' ?></span></td>
          <td class="text-end">
            <?php if ($t['status'] === 'Active'): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Check out this tenant?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="checkout">
                <input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>">
                <label class="small text-muted me-1"><input type="checkbox" name="keys_returned" checked> Keys returned</label>
                <button class="btn btn-sm btn-action-outline d-inline-flex align-items-center justify-content-center">Check Out</button>
              </form>
            <?php elseif (!$t['key_returned']): ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_key_returned">
                <input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>">
                <button class="btn btn-sm btn-action-outline d-inline-flex align-items-center justify-content-center">Mark Key Returned</button>
              </form>
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
