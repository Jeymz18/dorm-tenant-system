<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $tenantId = (int) ($_POST['tenant_id'] ?? 0);

    $t = $db->prepare('SELECT t.*, u.user_id FROM tenants t JOIN users u ON u.user_id = t.user_id WHERE t.tenant_id = ?');
    $t->execute([$tenantId]);
    $t = $t->fetch();

    if (!$t) {
        flash('error', 'Tenant not found.');
        redirect('/admin/manage-tenants.php');
    }

    if ($action === 'update_contact') {
        $first = str_input($_POST, 'first_name');
        $last  = str_input($_POST, 'last_name');
        $phone = str_input($_POST, 'phone');
        $type  = in_array($_POST['tenant_type'] ?? '', ['Student', 'Employee'], true) ? $_POST['tenant_type'] : 'Student';

        $phoneClean = ph_mobile_normalize($phone);
        if ($first === '' || $last === '') {
            flash('error', 'Name cannot be blank.');
        } elseif ($phone !== '' && $phoneClean === null) {
            flash('error', 'Phone must be a valid 10-digit mobile number (e.g. 912 123 1234).');
        } else {
            $db->prepare('UPDATE users SET first_name=?, last_name=?, phone=? WHERE user_id=?')
               ->execute([$first, $last, $phoneClean, $t['user_id']]);
            $db->prepare('UPDATE tenants SET tenant_type=? WHERE tenant_id=?')
               ->execute([$type, $tenantId]);
            flash('success', 'Tenant contact info updated.');
        }
    }

    if ($action === 'deactivate') {
        if ($t['user_id'] === current_user_id()) {
            flash('error', "You can't deactivate your own account.");
        } else {
            $db->prepare('UPDATE users SET is_active = FALSE WHERE user_id = ?')->execute([$t['user_id']]);
            flash('success', 'Tenant account deactivated — they can no longer log in.');
        }
    }

    redirect('/admin/manage-tenants.php');
}

$search = str_input($_GET, 'q');
$params = [];
$where = '';
if ($search !== '') {
    $where = "AND (u.first_name LIKE ? OR u.last_name LIKE ? OR r.room_number LIKE ?)";
    $like = "%$search%";
    $params = [$like, $like, $like];
}
$result = paginate(
    $db,
    "SELECT t.*, u.first_name, u.last_name, u.email, u.phone, u.is_active, r.room_number
     FROM tenants t
     JOIN users u ON u.user_id = t.user_id
     LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
     WHERE t.approval_status = 'Approved' $where
     ORDER BY t.date_registered DESC",
    "SELECT COUNT(*) c
     FROM tenants t
     JOIN users u ON u.user_id = t.user_id
     LEFT JOIN dorm_rooms r ON r.room_id = t.room_id
     WHERE t.approval_status = 'Approved' $where",
    $params
);
$tenants = $result['rows'];

$pageTitle = 'Manage Tenants';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/module_tabs.php';
require_once __DIR__ . '/../includes/page_header.php';
render_page_header('bi-people-fill', 'Tenant Directory', 'Manage approved tenant contact details, room assignments, and account status.');
render_module_tabs([
  ['key' => 'register', 'label' => 'Register Account', 'href' => '/admin/users.php'],
  ['key' => 'credentials', 'label' => 'Login Credentials', 'href' => '/admin/credentials.php'],
  ['key' => 'manage', 'label' => 'Manage Tenants', 'href' => '/admin/manage-tenants.php'],
], 'manage'); ?>

<div class="panel">
  <form class="search-box mb-3" method="get" style="max-width:none;">
    <i class="bi bi-search"></i>
    <input type="search" name="q" value="<?= clean($search) ?>" placeholder="Search by name, room, or email…" class="form-control">
  </form>
  <div class="table-responsive">
    <table class="table app-table align-middle">
      <thead><tr><th>Tenant</th><th>Contact</th><th>Room</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if (!$tenants): ?><tr><td colspan="5" class="text-center text-muted py-4">No tenants found.</td></tr><?php endif; ?>
        <?php foreach ($tenants as $t): ?>
          <tr>
            <td>
              <div class="cell-person">
                <div class="applicant-avatar"><i class="bi bi-person-fill"></i></div>
                <div><?= clean($t['first_name'] . ' ' . $t['last_name']) ?><div class="sub">Since <?= clean(date('n/j/Y', strtotime($t['date_registered']))) ?></div></div>
              </div>
            </td>
            <td class="text-muted small"><?= clean($t['email']) ?><?= $t['phone'] ? '<br>' . clean($t['phone']) : '' ?></td>
            <td><?= $t['room_number'] ? 'Room ' . clean($t['room_number']) : '<span class="text-muted">Unassigned</span>' ?></td>
            <td><span class="badge badge-<?= $t['is_active'] ? status_badge_class($t['status']) : 'secondary' ?>"><?= $t['is_active'] ? clean($t['status']) : 'Deactivated' ?></span></td>
            <td class="text-end">
              <button type="button" class="btn btn-sm btn-icon btn-action-outline" title="Edit" data-bs-toggle="modal" data-bs-target="#editTenantModal"
                data-id="<?= $t['tenant_id'] ?>" data-first="<?= clean($t['first_name']) ?>" data-last="<?= clean($t['last_name']) ?>"
                data-phone="<?= clean($t['phone'] ?? '') ?>" data-room="<?= $t['room_number'] ? 'Room ' . clean($t['room_number']) : 'Unassigned' ?>"
                data-status="<?= clean($t['status']) ?>" data-type="<?= clean($t['tenant_type']) ?>"><i class="bi bi-pencil-square"></i></button>
              <?php if ($t['is_active']): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Deactivate this tenant\'s account? They will no longer be able to log in. This does not delete their payment or contract history.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="deactivate">
                <input type="hidden" name="tenant_id" value="<?= $t['tenant_id'] ?>">
                <button class="btn btn-sm btn-icon btn-action-outline" title="Deactivate" aria-label="Deactivate tenant account"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($result['page'], $result['totalPages']) ?>
</div>

<!-- Edit Tenant Modal -->
<div class="modal fade" id="editTenantModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_contact">
        <input type="hidden" name="tenant_id" id="et_tenant_id">
        <div class="modal-header"><h5 class="modal-title">Edit Tenant Information</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">First Name</label><input class="form-control" name="first_name" id="et_first_name" required></div>
          <div class="mb-3"><label class="form-label">Last Name</label><input class="form-control" name="last_name" id="et_last_name" required></div>
          <div class="mb-3"><label class="form-label">Phone</label><input class="form-control" name="phone" id="et_phone"></div>
          <div class="mb-3">
            <label class="form-label">Tenant Type</label>
            <select class="form-select" name="tenant_type" id="et_type">
              <option value="Student">Student</option>
              <option value="Employee">Employee</option>
            </select>
          </div>
          <div class="mb-3"><label class="form-label">Room</label><input class="form-control" id="et_room" disabled></div>
          <div class="mb-1"><label class="form-label">Status</label><input class="form-control" id="et_status" disabled></div>
          <p class="text-muted small mt-2 mb-0">Room assignment and status changes happen in <a href="<?= BASE_URL ?>/admin/rooms.php">Property Management</a> and <a href="<?= BASE_URL ?>/admin/tenant-status.php">Track Status</a>, to keep room availability in sync.</p>
        </div>
        <div class="modal-footer"><button class="btn btn-sm btn-light" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-sm btn-action-primary">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = "<script>
document.getElementById('editTenantModal').addEventListener('show.bs.modal', function (e) {
  const btn = e.relatedTarget;
  document.getElementById('et_tenant_id').value = btn.dataset.id;
  document.getElementById('et_first_name').value = btn.dataset.first;
  document.getElementById('et_last_name').value = btn.dataset.last;
  document.getElementById('et_phone').value = btn.dataset.phone;
  document.getElementById('et_room').value = btn.dataset.room;
  document.getElementById('et_status').value = btn.dataset.status;
  document.getElementById('et_type').value = btn.dataset.type;
});
</script>";
include __DIR__ . '/../includes/footer.php';
?>
