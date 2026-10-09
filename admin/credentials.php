<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $getTargetRole = static function (int $userId) use ($db): ?string {
        $statement = $db->prepare('SELECT role FROM users WHERE user_id = ?');
        $statement->execute([$userId]);
        $role = $statement->fetchColumn();
        return is_string($role) ? $role : null;
    };
    $canManageTarget = static function (?string $targetRole): bool {
        return $targetRole !== null
            && $targetRole !== 'super_admin'
            && (is_super_admin() || $targetRole === 'tenant');
    };

    if ($action === 'update') {
        $id    = (int) ($_POST['user_id'] ?? 0);
      $targetRole = $getTargetRole($id);
        $first = str_input($_POST, 'first_name');
        $last  = str_input($_POST, 'last_name');
        $email = str_input($_POST, 'email');
      $requestedRole = str_input($_POST, 'role');
      $allowedRoles = is_super_admin() ? ['admin', 'tenant'] : ['tenant'];
      $role = in_array($requestedRole, $allowedRoles, true) ? $requestedRole : '';
        $newPassword = str_input($_POST, 'new_password', '', false);

        if (!$canManageTarget($targetRole)) {
            flash('error', 'You are not allowed to edit this account.');
        } elseif ($role === '') {
            flash('error', 'Only Super Admins can assign administrator accounts.');
        } elseif ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please fill every field correctly.');
        } elseif ($newPassword !== '' && password_policy_error($newPassword) !== null) {
          flash('error', password_policy_error($newPassword));
        } else {
            $dupe = $db->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ?');
            $dupe->execute([$email, $id]);
            if ($dupe->fetch()) {
                flash('error', 'Another account already uses that email.');
            } else {
                if ($newPassword !== '') {
                    $db->prepare('UPDATE users SET first_name=?, last_name=?, email=?, role=?, password_hash=? WHERE user_id=?')
                       ->execute([$first, $last, $email, $role, password_hash($newPassword, PASSWORD_BCRYPT), $id]);
                } else {
                    $db->prepare('UPDATE users SET first_name=?, last_name=?, email=?, role=? WHERE user_id=?')
                       ->execute([$first, $last, $email, $role, $id]);
                }
                if ($targetRole !== $role) {
                  log_action('user_role_modified', 'Changed role for user #' . $id . ' from ' . $targetRole . ' to ' . $role);
                }
                flash('success', 'Account updated.');
            }
        }
    }

    if ($action === 'toggle_active') {
        $id = (int) ($_POST['user_id'] ?? 0);
        if (!$canManageTarget($getTargetRole($id))) {
            flash('error', 'You are not allowed to change this account status.');
        } elseif ($id === current_user_id()) {
            flash('error', "You can't deactivate your own account while logged in.");
        } else {
            $db->prepare('UPDATE users SET is_active = NOT is_active WHERE user_id = ?')->execute([$id]);
            flash('success', 'Account status updated.');
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['user_id'] ?? 0);
        if (!$canManageTarget($getTargetRole($id))) {
            flash('error', 'You are not allowed to delete this account.');
        } elseif ($id === current_user_id()) {
            flash('error', "You can't delete your own account while logged in.");
      } elseif (str_input($_POST, 'confirm_delete') !== 'DELETE') {
        flash('error', 'Type DELETE exactly to confirm account deletion.');
        } else {
            try {
                $db->beginTransaction();
              log_action('user_deleted', 'Deleted user #' . $id . ' with role ' . $getTargetRole($id));
                $db->prepare('DELETE FROM notifications WHERE sender_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM reports WHERE generated_by = ?')->execute([$id]);
                $db->prepare('DELETE FROM users WHERE user_id = ?')->execute([$id]);
                $db->commit();
                flash('success', 'Account deleted.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                flash('error', 'Account could not be deleted. Please try again.');
            }
        }
    }

    redirect('/admin/credentials.php');
}

$search = str_input($_GET, 'q');
if ($search !== '') {
    $like = "%$search%";
    $result = paginate(
        $db,
        "SELECT * FROM users WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? ORDER BY date_created DESC",
        "SELECT COUNT(*) c FROM users WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ?",
        [$like, $like, $like]
    );
} else {
    $result = paginate($db, "SELECT * FROM users ORDER BY date_created DESC", "SELECT COUNT(*) c FROM users");
}
$users = $result['rows'];

$pageTitle = 'Log In Credentials';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/module_tabs.php';
require_once __DIR__ . '/../includes/page_header.php';
render_page_header('bi-shield-lock-fill', 'Security Credentials', 'Manage user access, password records, and account security settings.');
render_module_tabs([
  ['key' => 'register', 'label' => 'Register Account', 'href' => '/admin/users.php'],
  ['key' => 'credentials', 'label' => 'Login Credentials', 'href' => '/admin/credentials.php'],
  ['key' => 'manage', 'label' => 'Manage Tenants', 'href' => '/admin/manage-tenants.php'],
], 'credentials'); ?>

<div class="panel">
  <div class="panel-header">
    <h2>System Users</h2>
    <form class="search-box" method="get">
      <i class="bi bi-search"></i>
      <input type="search" name="q" value="<?= clean($search) ?>" placeholder="Search by name or email…" class="form-control form-control-sm">
    </form>
  </div>
  <div class="table-responsive">
    <table class="table app-table align-middle">
      <thead><tr><th>User</th><th>Password</th><th>Role</th><th>Last Login</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (!$users): ?><tr><td colspan="6" class="text-center text-muted py-4">No users found.</td></tr><?php endif; ?>
        <?php foreach ($users as $u): ?>
          <tr>
            <td>
              <div class="cell-person">
                <div class="applicant-avatar"><i class="bi bi-person-fill" aria-hidden="true"></i></div>
                <div><?= clean($u['first_name'] . ' ' . $u['last_name']) ?><div class="sub"><?= clean($u['email']) ?></div></div>
              </div>
            </td>
            <td class="text-muted">••••••••</td>
            <td><span class="badge badge-<?= is_admin_role((string) $u['role']) ? 'maroon' : 'info' ?>"><?= clean(ucwords(str_replace('_', ' ', $u['role']))) ?></span></td>
            <td class="text-muted small"><?= $u['last_login'] ? clean(date('n/j/Y g:i A', strtotime($u['last_login']))) : 'Never' ?></td>
            <td><span class="badge badge-<?= $u['is_active'] ? 'success' : 'secondary' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="text-end">
              <?php if ($u['role'] !== 'super_admin' && (is_super_admin() || $u['role'] === 'tenant')): ?>
              <button type="button" class="btn btn-sm btn-icon btn-action-outline" title="Edit" data-bs-toggle="modal" data-bs-target="#editUserModal"
                data-id="<?= $u['user_id'] ?>" data-first="<?= clean($u['first_name']) ?>" data-last="<?= clean($u['last_name']) ?>"
                data-email="<?= clean($u['email']) ?>" data-role="<?= clean($u['role']) ?>"><i class="bi bi-pencil-square"></i></button>
              <form method="post" class="d-inline" onsubmit="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?> this account?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                <button class="btn btn-sm btn-icon btn-action-outline" title="<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?>" aria-label="<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?> account"><?= $u['is_active'] ? '<i class="bi bi-slash-circle" aria-hidden="true"></i>' : '<i class="bi bi-check-circle-fill" aria-hidden="true"></i>' ?></button>
              </form>
              <?php if ((int) $u['user_id'] !== current_user_id()): ?>
              <button type="button" class="btn btn-sm btn-icon btn-action-outline text-danger delete-user-btn" data-userid="<?= (int) $u['user_id'] ?>" title="Delete account" aria-label="Delete account"><i class="bi bi-trash3" aria-hidden="true"></i></button>
              <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($result['page'], $result['totalPages']) ?>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" class="needs-validation" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="user_id" id="edit_user_id">
        <div class="modal-header"><h5 class="modal-title">Edit Account</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">First Name</label><input class="form-control" name="first_name" id="edit_first_name" required></div>
            <div class="col-md-6"><label class="form-label">Last Name</label><input class="form-control" name="last_name" id="edit_last_name" required></div>
          </div>
          <div class="mb-3 mt-3"><label class="form-label">Email Address</label><input type="email" class="form-control" name="email" id="edit_email" required></div>
          <div class="mb-3">
            <label class="form-label">New Password <span class="text-muted">(leave blank to keep current)</span></label>
            <input type="password" class="form-control" name="new_password" value="" autocomplete="new-password" minlength="8">
          </div>
          <div class="mb-1">
            <label class="form-label">Role</label>
            <select class="form-select" name="role" id="edit_role">
              <option value="tenant">Tenant</option>
              <?php if (is_super_admin()): ?><option value="admin">Administrator</option><?php endif; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-sm btn-light" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-sm btn-action-primary">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Secure Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="deleteConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>Confirm Permanent Deletion</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="deleteForm" method="post" action="<?= BASE_URL ?>/admin/credentials.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <div class="modal-body">
          <input type="hidden" name="user_id" id="deleteUserId">
          <p class="text-muted small mb-3">Permanently delete this account? Its tenant records, payments, contracts, and maintenance history will also be deleted. This cannot be undone.</p>
          <div class="mb-3">
            <label for="confirmDeleteInput" class="form-label small fw-bold">To confirm, type <span class="text-danger">DELETE</span> below:</label>
            <input type="text" class="form-control" name="confirm_delete" id="confirmDeleteInput" placeholder="Type DELETE to enable" autocomplete="off" required>
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" id="confirmDeleteBtn" class="btn btn-danger btn-sm rounded-pill px-3" disabled>Delete Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = "<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('editUserModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    if (!btn) return;
    const fields = {
      userId: document.getElementById('edit_user_id'),
      first: document.getElementById('edit_first_name'),
      last: document.getElementById('edit_last_name'),
      email: document.getElementById('edit_email'),
      role: document.getElementById('edit_role')
    };
    if (!fields.userId || !fields.first || !fields.last || !fields.email || !fields.role) return;
    fields.userId.value = btn.dataset.id || '';
    fields.first.value = btn.dataset.first || '';
    fields.last.value = btn.dataset.last || '';
    fields.email.value = btn.dataset.email || '';
    fields.role.value = btn.dataset.role || 'tenant';
  });

  const deleteModal = document.getElementById('deleteConfirmModal');
  if (!deleteModal) return;

  const deleteUserIdInput = document.getElementById('deleteUserId');
  const confirmInput = document.getElementById('confirmDeleteInput');
  const confirmBtn = document.getElementById('confirmDeleteBtn');
  document.querySelectorAll('.delete-user-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      deleteUserIdInput.value = this.getAttribute('data-userid') || '';
      confirmInput.value = '';
      confirmBtn.disabled = true;
      bootstrap.Modal.getOrCreateInstance(deleteModal).show();
    });
  });

  confirmInput.addEventListener('input', function () {
    confirmBtn.disabled = this.value.trim() !== 'DELETE';
  });

  deleteModal.addEventListener('hidden.bs.modal', function () {
    document.getElementById('deleteForm').reset();
    confirmBtn.disabled = true;
  });
});
</script>";
include __DIR__ . '/../includes/footer.php';
?>
