<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $first = str_input($_POST, 'first_name');
    $last  = str_input($_POST, 'last_name');
    $email = str_input($_POST, 'email');
    $phone = str_input($_POST, 'phone');
    $emergencyContact = str_input($_POST, 'emergency_contact');
    $emergencyPhone   = str_input($_POST, 'emergency_phone');
    $newPassword = str_input($_POST, 'new_password', '', false);
    $phoneClean = ph_mobile_normalize($phone);
    $emergencyClean = ph_mobile_normalize($emergencyPhone);

    if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Please fill in a valid name and email.');
    } elseif (($phone !== '' && $phoneClean === null) || ($emergencyPhone !== '' && $emergencyClean === null)) {
        flash('error', 'Phone numbers must be a valid 10-digit mobile number (e.g. 912 123 1234).');
    } elseif ($newPassword !== '' && password_policy_error($newPassword) !== null) {
        flash('error', (string) password_policy_error($newPassword));
    } else {
        $db->beginTransaction();
        try {
            if ($newPassword !== '') {
                $db->prepare('UPDATE users SET first_name=?, last_name=?, email=?, phone=?, password_hash=?, password_changed_at=NOW() WHERE user_id=?')
                   ->execute([$first, $last, $email, $phoneClean, password_hash($newPassword, PASSWORD_DEFAULT), current_user_id()]);
            } else {
                $db->prepare('UPDATE users SET first_name=?, last_name=?, email=?, phone=? WHERE user_id=?')
                   ->execute([$first, $last, $email, $phoneClean, current_user_id()]);
            }
            $db->prepare('UPDATE tenants SET emergency_contact=?, emergency_phone=? WHERE user_id=?')
               ->execute([$emergencyContact ?: null, $emergencyClean, current_user_id()]);

            $db->commit();
            $_SESSION['first_name'] = $first;
            $_SESSION['last_name']  = $last;
            $_SESSION['email']      = $email;
            if ($newPassword !== '') {
                $changedAt = $db->prepare('SELECT password_changed_at FROM users WHERE user_id = ?');
                $changedAt->execute([current_user_id()]);
                $_SESSION['pwd_changed_at'] = $changedAt->fetchColumn();
            }
            flash('success', 'Profile updated.');
        } catch (Exception $e) {
            $db->rollBack();
            flash('error', 'Could not update profile. Please try again.');
        }
    }
    redirect('/tenant/profile.php');
}

$stmt = $db->prepare('SELECT u.*, t.tenant_id, t.emergency_contact, t.emergency_phone FROM users u JOIN tenants t ON t.user_id = u.user_id WHERE u.user_id = ?');
$stmt->execute([current_user_id()]);
$profile = $stmt->fetch();

$contactName = preg_replace('/\s*\([^)]*\)$/', '', (string) ($profile['emergency_contact'] ?? ''));

$pageTitle = 'My Profile';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/page_header.php';
?>
<?php render_page_header('bi-person-circle', 'My Profile', 'Manage your personal information and contact details.', null, 'Tenant Portal', '/tenant/dashboard.php'); ?>

<div class="container-lg">
  <div class="row justify-content-center">
    <div class="col-lg-10">
      <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3">
          <div class="d-flex align-items-center gap-3">
            <div class="avatar-circle bg-maroon-subtle text-maroon fw-bold fs-4 d-flex align-items-center justify-content-center rounded-circle" style="width: 56px; height: 56px;">
              <?= clean(strtoupper(substr($profile['first_name'], 0, 1))) ?>
            </div>
            <div>
              <h2 class="mb-0"><?= clean($profile['first_name'] . ' ' . $profile['last_name']) ?></h2>
              <span class="text-muted">Tenant · TEN-<?= str_pad((string) $profile['tenant_id'], 4, '0', STR_PAD_LEFT) ?></span>
            </div>
          </div>

          <button type="button" id="toggleEditBtn" class="btn btn-action-light">
            <i class="bi bi-pencil-square"></i>
            <span>Edit Profile</span>
          </button>
        </div>
      </div>

      <form id="profileForm" method="post" class="mx-auto" style="max-width: 960px;">
        <?= csrf_field() ?>
        <div class="split-form">
          <div class="panel">
            <p class="fw-semibold small mb-3">Personal Information</p>
            <div class="mb-3"><label class="form-label">First Name</label><input class="form-control bg-light border-0 edit-field" name="first_name" value="<?= clean($profile['first_name']) ?>" readonly required></div>
            <div class="mb-3"><label class="form-label">Last Name</label><input class="form-control bg-light border-0 edit-field" name="last_name" value="<?= clean($profile['last_name']) ?>" readonly required></div>
            <div class="mb-3"><label class="form-label">Email Address</label><input type="email" class="form-control bg-light border-0 edit-field" name="email" value="<?= clean($profile['email']) ?>" readonly required></div>
            <div class="mb-3"><label class="form-label">Phone Number</label><input class="form-control bg-light border-0 edit-field" name="phone" value="<?= clean($profile['phone'] ?? '') ?>" readonly></div>
            <div class="mb-0"><label class="form-label">Tenant ID</label><input class="form-control" value="TEN-<?= str_pad((string) $profile['tenant_id'], 4, '0', STR_PAD_LEFT) ?>" disabled></div>
          </div>
          <div class="panel">
            <p class="fw-semibold small mb-3">Emergency Contact</p>
            <div class="mb-3"><label class="form-label">Contact Name</label><input class="form-control bg-light border-0 edit-field" name="emergency_contact" value="<?= clean($contactName) ?>" readonly required></div>
            <div class="mb-0"><label class="form-label">Phone Number</label><input class="form-control bg-light border-0 edit-field" name="emergency_phone" value="<?= clean($profile['emergency_phone'] ?? '') ?>" readonly required></div>
          </div>
        </div>

        <div class="panel mt-3">
          <p class="fw-semibold small mb-3">Change Password</p>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">New Password <span class="text-muted">(leave blank to keep current)</span></label>
              <input type="password" class="form-control edit-field" name="new_password" value="" autocomplete="new-password" minlength="8" readonly>
            </div>
          </div>
        </div>

        <div id="saveProfileBar" class="d-none mt-3 text-end" style="max-width: 960px;">
          <button type="button" id="cancelEditBtn" class="btn btn-action-light rounded-pill px-4 me-2">Cancel</button>
          <button type="submit" class="btn btn-sm btn-action-primary rounded-pill px-4">Save Changes</button>
        </div>
      </form>

      <form method="post" action="<?= BASE_URL ?>/auth/logout.php" class="mt-4 mx-auto" style="max-width: 960px;"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-action-primary w-100"><i class="bi bi-box-arrow-right"></i> Log Out</button></form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const toggleBtn = document.getElementById('toggleEditBtn');
  const cancelBtn = document.getElementById('cancelEditBtn');
  const saveBar = document.getElementById('saveProfileBar');
  const fields = document.querySelectorAll('.edit-field');
  const originalValues = new Map();
  fields.forEach(function (field) { originalValues.set(field, field.value); });

  function setEditing(isEditing) {
    fields.forEach(function (field) {
      if (isEditing) {
        field.removeAttribute('readonly');
        field.classList.remove('bg-light', 'border-0');
        field.classList.add('bg-white', 'border');
      } else {
        field.setAttribute('readonly', 'readonly');
        field.classList.remove('bg-white', 'border');
        field.classList.add('bg-light', 'border-0');
      }
    });

    if (saveBar) {
      saveBar.classList.toggle('d-none', !isEditing);
    }
  }

  if (toggleBtn) {
    toggleBtn.addEventListener('click', function () {
      const isEditing = !fields.length || !fields[0].hasAttribute('readonly');
      setEditing(!isEditing);
    });
  }

  if (cancelBtn) {
    cancelBtn.addEventListener('click', function () {
      fields.forEach(function (field) {
        field.value = originalValues.get(field) ?? '';
      });
      setEditing(false);
    });
  }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
