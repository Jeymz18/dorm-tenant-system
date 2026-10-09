<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();
$teams = ['Electrician Team', 'Plumber Team', 'HVAC Team', 'Maintenance Crew A', 'Maintenance Crew B'];
$issueTypes = ['Plumbing', 'Electrical', 'HVAC / Air Conditioning', 'Heating', 'Furniture', 'Security / Locks', 'Pest Control', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['maintenance_id'] ?? 0);

    if ($action === 'assign') {
        $team = $_POST['team'] ?? '';
        if (!in_array($team, $teams, true)) {
            flash('error', 'Please choose a valid team.');
        } else {
            $db->prepare("UPDATE maintenance_requests SET status='Ongoing', is_resolved=FALSE, assigned_to=?, updated_at=NOW() WHERE maintenance_id=?")->execute([$team, $id]);
            $mTenant = $db->prepare('SELECT tenant_id FROM maintenance_requests WHERE maintenance_id=?');
            $mTenant->execute([$id]);
            log_activity($db, 'maintenance_updated', "Request #$id assigned to $team", $mTenant->fetch()['tenant_id'] ?? null);
            flash('success', "Task assigned to $team.");
        }
    }

    if ($action === 'complete') {
        $db->prepare("UPDATE maintenance_requests SET status='Completed', is_resolved=TRUE, date_resolved=CURDATE(), updated_at=NOW() WHERE maintenance_id=?")->execute([$id]);
        $mTenant = $db->prepare('SELECT tenant_id FROM maintenance_requests WHERE maintenance_id=?');
        $mTenant->execute([$id]);
        log_activity($db, 'maintenance_updated', "Request #$id marked completed", $mTenant->fetch()['tenant_id'] ?? null);
        flash('success', 'Marked as completed.');
    }

    if ($action === 'update_status') {
        $status = $_POST['status'] ?? '';
        $team = trim((string) ($_POST['team'] ?? ''));

        if (!in_array($status, ['Pending', 'Ongoing', 'Completed'], true)) {
            flash('error', 'Please choose a valid maintenance status.');
        } else {
            $assignedTo = $team !== '' ? $team : null;
            $resolvedDate = $status === 'Completed' ? date('Y-m-d') : null;
            $db->prepare('UPDATE maintenance_requests SET status=?, is_resolved=?, assigned_to=?, date_resolved=?, updated_at=NOW() WHERE maintenance_id=?')
              ->execute([$status, $status === 'Completed', $assignedTo, $resolvedDate, $id]);

            $mTenant = $db->prepare('SELECT tenant_id FROM maintenance_requests WHERE maintenance_id=?');
            $mTenant->execute([$id]);
            log_activity($db, 'maintenance_updated', "Request #$id updated to $status" . ($assignedTo ? " and assigned to $assignedTo" : ''), $mTenant->fetch()['tenant_id'] ?? null);
            flash('success', 'Maintenance request updated.');
        }
    }

    if ($action === 'log_request') {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $title    = str_input($_POST, 'issue_type');
        $desc     = str_input($_POST, 'description');
        $priority = in_array($_POST['priority_level'] ?? '', ['Low', 'Medium', 'High', 'Urgent'], true) ? $_POST['priority_level'] : 'Medium';

        $t = $db->prepare('SELECT room_id FROM tenants WHERE tenant_id = ? AND room_id IS NOT NULL');
        $t->execute([$tenantId]);
        $t = $t->fetch();

        if (!$t) {
            flash('error', 'Please choose a tenant with an assigned room.');
        } elseif ($title === '' || $desc === '') {
            flash('error', 'Please choose an issue type and describe the problem.');
        } else {
            $db->prepare('INSERT INTO maintenance_requests (tenant_id, room_id, issue_title, issue_description, priority_level) VALUES (?,?,?,?,?)')
               ->execute([$tenantId, $t['room_id'], $title, $desc, $priority]);
            log_activity($db, 'maintenance_submitted', $title . ' request logged by admin', $tenantId);
            flash('success', 'Maintenance request logged.');
        }
    }

    redirect('/admin/maintenance.php');
}

$search = str_input($_GET, 'q');
$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ['all', 'Pending', 'Ongoing', 'Completed'], true)) {
    $statusFilter = 'all';
}

$where = [];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'm.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(m.issue_title LIKE ? OR r.room_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$statusCounts = ['Pending' => 0, 'Ongoing' => 0, 'Completed' => 0];
foreach ($db->query("SELECT status, COUNT(*) c FROM maintenance_requests GROUP BY status") as $row) {
    $statusCounts[$row['status']] = (int) $row['c'];
}
$totalCount = array_sum($statusCounts);

$result = paginate(
    $db,
    "SELECT m.*, u.first_name, u.last_name, r.room_number
     FROM maintenance_requests m
     JOIN tenants t ON t.tenant_id = m.tenant_id
     JOIN users u ON u.user_id = t.user_id
     JOIN dorm_rooms r ON r.room_id = m.room_id
     $whereSql
     ORDER BY FIELD(m.status,'Pending','Ongoing','Completed'), FIELD(m.priority_level,'Urgent','High','Medium','Low'), m.date_submitted ASC",
    "SELECT COUNT(*) c FROM maintenance_requests m
     JOIN tenants t ON t.tenant_id = m.tenant_id
     JOIN users u ON u.user_id = t.user_id
     JOIN dorm_rooms r ON r.room_id = m.room_id
     $whereSql",
    $params,
    10
);
$requests = $result['rows'];

// Active tenants with a room assigned — the only ones a request can be logged for.
$roomedTenants = $db->query("
    SELECT t.tenant_id, u.first_name, u.last_name, r.room_number
    FROM tenants t
    JOIN users u ON u.user_id = t.user_id
    JOIN dorm_rooms r ON r.room_id = t.room_id
    WHERE t.status = 'Active'
    ORDER BY u.first_name
")->fetchAll();

$pageTitle = 'Maintenance Management';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/module_tabs.php';
require_once __DIR__ . '/../includes/page_header.php';
render_page_header(
    'bi-wrench-adjustable',
    'Maintenance Management',
    'Log requests, assign tasks to teams, and track resolution progress.',
    '<button type="button" class="btn btn-sm btn-action-primary" data-bs-toggle="modal" data-bs-target="#logRequestModal"><i class="bi bi-plus-lg"></i> Log New Request</button>'
);
$tabKey = $statusFilter === 'Pending' ? 'assign' : ($statusFilter === 'Ongoing' ? 'status' : 'requests');
render_module_tabs([
  ['key' => 'requests', 'label' => 'View Requests', 'href' => '/admin/maintenance.php'],
  ['key' => 'assign', 'label' => 'Assign Tasks', 'href' => '/admin/maintenance.php?status=Pending'],
  ['key' => 'status', 'label' => 'Track Status', 'href' => '/admin/maintenance.php?status=Ongoing'],
], $tabKey);

$statusPills = ['all' => 'All', 'Pending' => 'Pending', 'Ongoing' => 'Ongoing', 'Completed' => 'Completed'];
$priorityBadge = ['Urgent' => 'danger', 'High' => 'warning', 'Medium' => 'info', 'Low' => 'secondary'];
?>
<div class="stat-grid stat-grid-3">
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Pending</div><div class="stat-value"><?= $statusCounts['Pending'] ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-clock-fill"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Ongoing</div><div class="stat-value"><?= $statusCounts['Ongoing'] ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-tools"></i></div></div>
  <div class="stat-card"><div class="stat-card-body"><div class="stat-label">Completed</div><div class="stat-value"><?= $statusCounts['Completed'] ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi bi-check-lg"></i></div></div>
</div>

<div class="panel mt-2 anchor-target" id="maintenance-requests">
  <div class="filter-toolbar">
    <form class="search-box" method="get">
      <i class="bi bi-search"></i>
      <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= clean($statusFilter) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= clean($search) ?>" placeholder="Search title, room, tenant…" class="form-control form-control-sm">
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

  <?php if (!$requests): ?>
    <p class="text-muted text-center py-4">No maintenance requests found.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table app-table table-maintenance align-middle">
      <thead><tr><th class="maintenance-expand-column" aria-label="Expand"></th><th>Request</th><th>Priority</th><th>Status</th><th>Reported</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($requests as $m): ?>
        <tr class="main-row clickable-row" role="button" data-bs-toggle="collapse" data-bs-target="#details-<?= $m['maintenance_id'] ?>" aria-controls="details-<?= $m['maintenance_id'] ?>" aria-expanded="false">
          <td class="text-center maintenance-expand-column"><i class="bi bi-chevron-down toggle-icon text-muted" aria-hidden="true"></i></td>
          <td><div class="fw-bold text-dark"><?= clean($m['issue_title']) ?></div><div class="text-muted small"><?= clean(mb_strimwidth($m['issue_description'], 0, 60, '…')) ?></div></td>
          <td><span class="badge maintenance-badge badge-<?= $priorityBadge[$m['priority_level']] ?? 'secondary' ?>"><?= clean($m['priority_level']) ?></span></td>
          <td><span class="badge maintenance-badge badge-<?= status_badge_class($m['status']) ?>">● <?= clean($m['status']) ?></span></td>
          <td class="small text-muted"><?= clean(date('n/j/Y', strtotime($m['date_submitted']))) ?></td>
          <td class="text-end" onclick="event.stopPropagation();" onmousedown="event.stopPropagation();" onfocus="event.stopPropagation();">
            <div class="d-flex justify-content-end gap-2 flex-wrap align-items-center">
              <?php if ($m['status'] === 'Pending'): ?>
                <form method="POST" action="maintenance.php" class="d-inline-flex align-items-center m-0" onclick="event.stopPropagation();" onmousedown="event.stopPropagation();">
                  <?= csrf_field() ?>
                  <input type="hidden" name="maintenance_id" value="<?= $m['maintenance_id'] ?>">
                  <input type="hidden" name="request_id" value="<?= $m['maintenance_id'] ?>">
                  <input type="hidden" name="action" value="assign">
                  <div class="input-group maintenance-assign-group">
                    <select name="team" class="form-select" required onclick="event.stopPropagation();" onmousedown="event.stopPropagation();" onfocus="event.stopPropagation();">
                      <option value="" disabled selected>Assign...</option>
                      <?php foreach ($teams as $team): ?><option value="<?= clean($team) ?>"><?= clean($team) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-action-outline" onclick="event.stopPropagation();" onmousedown="event.stopPropagation();">Go</button>
                  </div>
                </form>
              <?php elseif ($m['status'] === 'Ongoing'): ?>
                <form method="post" class="d-inline" onclick="event.stopPropagation();" onmousedown="event.stopPropagation();"><?= csrf_field() ?><input type="hidden" name="action" value="complete"><input type="hidden" name="maintenance_id" value="<?= $m['maintenance_id'] ?>"><button type="submit" class="btn btn-sm btn-action-outline" onclick="event.stopPropagation();" onmousedown="event.stopPropagation();">Mark Completed</button></form>
              <?php else: ?>
                <span class="text-muted small"><?= $m['date_resolved'] ? clean(date('n/j/Y', strtotime($m['date_resolved']))) : '—' ?></span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <tr id="details-<?= $m['maintenance_id'] ?>" class="collapse expanded-detail-row">
          <td colspan="6" class="expanded-drawer-td">
            <div class="drawer-content-wrapper">
            <div class="row g-3">
              <div class="col-md-7">
                <div class="detail-card mb-3">
                <div class="section-title"><i class="bi bi-card-text me-2" aria-hidden="true"></i>Request Details</div>
                <div class="detail-description">
                  <div class="fw-semibold mb-1"><?= clean($m['issue_title']) ?></div>
                  <div class="text-secondary detail-description-text"><?= nl2br(clean($m['issue_description'])) ?></div>
                </div>
                </div>
                <div class="detail-card">
                <div class="section-title"><i class="bi bi-info-circle me-2" aria-hidden="true"></i>Maintenance Metadata</div>
                <div class="row g-2 detail-metadata">
                  <div class="col-6"><div class="detail-label">Tenant</div><div class="detail-value"><?= clean($m['first_name'] . ' ' . $m['last_name']) ?></div></div>
                  <div class="col-6"><div class="detail-label">Room / Unit</div><div class="detail-value">Room <?= clean($m['room_number']) ?></div></div>
                  <div class="col-6"><div class="detail-label">Category</div><div class="detail-value"><?= clean(maintenance_category_bucket($m['issue_title'])) ?></div></div>
                  <div class="col-6"><div class="detail-label">Priority</div><div class="detail-value"><?= clean($m['priority_level']) ?></div></div>
                  <div class="col-6"><div class="detail-label">Status</div><div class="detail-value"><?= clean($m['status']) ?></div></div>
                  <div class="col-6"><div class="detail-label">Assigned Team / Worker</div><div class="detail-value"><?= clean($m['assigned_to'] ?: 'Unassigned') ?></div></div>
                  <div class="col-6"><div class="detail-label">Date Submitted</div><div class="detail-value"><?= clean(date('M j, Y g:i A', strtotime($m['date_submitted']))) ?></div></div>
                  <div class="col-6"><div class="detail-label">Date Resolved</div><div class="detail-value"><?= $m['date_resolved'] ? clean(date('M j, Y', strtotime($m['date_resolved']))) : '—' ?></div></div>
                </div>
                </div>
              </div>
              <div class="col-md-5">
                <div class="detail-card h-100">
                <div class="section-title"><i class="bi bi-paperclip me-2" aria-hidden="true"></i>Attached Maintenance Photo</div>
                <?php if (!empty($m['photo_file'])): ?>
                  <div class="photo-preview-container">
                    <a href="<?= BASE_URL . '/' . ltrim($m['photo_file'], '/') ?>" target="_blank" rel="noopener" class="d-block">
                      <img src="<?= BASE_URL . '/' . ltrim($m['photo_file'], '/') ?>" alt="Tenant uploaded maintenance photo">
                      <span class="photo-overlay-badge"><i class="bi bi-arrows-angle-expand me-1" aria-hidden="true"></i>View Full Photo</span>
                    </a>
                  </div>
                <?php else: ?>
                  <div class="text-center p-3 text-muted bg-light rounded-3">
                    <i class="bi bi-image fs-2 d-block mb-1" aria-hidden="true"></i>
                    <span>No photo uploaded for this request.</span>
                  </div>
                <?php endif; ?>
                </div>
              </div>
            </div>
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

<!-- Log New Request Modal -->
<div class="modal fade" id="logRequestModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="log_request">
        <div class="modal-header"><h5 class="modal-title">Log New Maintenance Request</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?php if (!$roomedTenants): ?>
            <p class="text-muted">No active tenants with an assigned room yet.</p>
          <?php else: ?>
            <label class="form-label">Tenant</label>
            <select class="form-select mb-3" name="tenant_id" required>
              <option value="">Choose…</option>
              <?php foreach ($roomedTenants as $t): ?><option value="<?= $t['tenant_id'] ?>"><?= clean($t['first_name'] . ' ' . $t['last_name']) ?> — Room <?= clean($t['room_number']) ?></option><?php endforeach; ?>
            </select>
            <label class="form-label">Issue Type</label>
            <select class="form-select mb-3" name="issue_type" required>
              <option value="">Choose…</option>
              <?php foreach ($issueTypes as $type): ?><option><?= clean($type) ?></option><?php endforeach; ?>
            </select>
            <label class="form-label">Priority</label>
            <select class="form-select mb-3" name="priority_level">
              <option>Low</option><option selected>Medium</option><option>High</option><option>Urgent</option>
            </select>
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" rows="3" required placeholder="Describe the issue…"></textarea>
          <?php endif; ?>
        </div>
        <div class="modal-footer"><button class="btn btn-sm btn-light" data-bs-dismiss="modal" type="button">Cancel</button><?php if ($roomedTenants): ?><button class="btn btn-sm btn-action-primary">Log Request</button><?php endif; ?></div>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
