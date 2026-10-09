<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();

// ---- Handle actions -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $first = str_input($_POST, 'first_name');
        $last  = str_input($_POST, 'last_name');
        $email = str_input($_POST, 'email');
        $password = str_input($_POST, 'password', '', false);
        $confirmPassword = str_input($_POST, 'confirm_password', '', false);
        $requestedRole = str_input($_POST, 'role');
        $role = in_array($requestedRole, ['admin', 'tenant'], true) ? $requestedRole : 'tenant';
        $contactNumber = str_input($_POST, 'contact_number');
        $tenantType = in_array($_POST['tenant_type'] ?? '', ['Student', 'Employee'], true) ? $_POST['tenant_type'] : 'Student';
        $emergencyName = str_input($_POST, 'emergency_contact_name');
        $emergencyPhone = str_input($_POST, 'emergency_contact_phone');
        $cleanEmergencyPhone = ph_mobile_normalize($emergencyPhone) ?? '';
        $emergencyPhoneInvalid = $emergencyPhone !== '' && $cleanEmergencyPhone === '';
        $cleanContact = ph_mobile_normalize($contactNumber);

        if ($requestedRole === 'admin' && !is_super_admin()) {
            flash('error', 'Only Super Admins can register administrator accounts.');
        } elseif ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($requestedRole, ['admin', 'tenant'], true) || password_policy_error($password) !== null || $password !== $confirmPassword || $cleanContact === null || $emergencyPhoneInvalid) {
            flash('error', 'Please fill every field correctly (password needs at least 8 characters).');
        } else {
            $check = $db->prepare('SELECT user_id FROM users WHERE email = ?');
            $check->execute([$email]);
            if ($check->fetch()) {
                flash('error', 'That email is already registered.');
            } else {
                 $db->prepare('INSERT INTO users (first_name, last_name, email, password_hash, phone, role) VALUES (?, ?, ?, ?, ?, ?)')
                   ->execute([$first, $last, $email, password_hash($password, PASSWORD_DEFAULT), $cleanContact, $role]);
                 $newId = (int) $db->lastInsertId();
                 if ($role === 'tenant') {
                     $cleanPhone = $cleanContact;
                     $db->prepare('INSERT INTO tenants (user_id, status, approval_status, tenant_type, contact_number, emergency_contact_name, emergency_contact_phone, emergency_contact, emergency_phone) VALUES (?, "Pending", "Pending", ?, ?, ?, ?, ?, ?)')
                       ->execute([$newId, $tenantType, $cleanPhone ?: null, $emergencyName ?: null, $cleanEmergencyPhone ?: null, $emergencyName ?: null, $cleanEmergencyPhone ?: null]);
                     log_activity($db, 'tenant_registered', $first . ' ' . $last . ' was registered by an admin', (int) $db->lastInsertId());
                 }
                flash('success', 'Account created for ' . $first . ' ' . $last . '.');
            }
        }
    }

    redirect('/admin/users.php');
}

$pageTitle = 'Register Account';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/module_tabs.php';
require_once __DIR__ . '/../includes/page_header.php';
render_page_header('bi-person-gear', 'User Accounts', 'Register accounts, manage login credentials, and assign roles.');
render_module_tabs([
  ['key' => 'register', 'label' => 'Register Account', 'href' => '/admin/users.php'],
  ['key' => 'credentials', 'label' => 'Login Credentials', 'href' => '/admin/credentials.php'],
  ['key' => 'manage', 'label' => 'Manage Tenants', 'href' => '/admin/manage-tenants.php'],
], 'register'); ?>

<div class="panel">
  <form method="post" id="addUserForm" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="registration-columns">
      <section class="registration-card">
        <div class="registration-card-heading"><span class="registration-icon"><i class="bi bi-person-plus"></i></span><h5>User Information</h5></div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">First Name <span class="text-danger">*</span></label><input class="form-control" name="first_name" placeholder="Enter first name" required></div>
          <div class="col-md-6"><label class="form-label">Last Name <span class="text-danger">*</span></label><input class="form-control" name="last_name" placeholder="Enter last name" required></div>
        </div>
        <div class="mb-3 mt-3"><label class="form-label">Email Address <span class="text-danger">*</span></label><input type="email" class="form-control" name="email" placeholder="user@email.com" required></div>
        <div class="mb-3"><label class="form-label">Phone Number <span class="text-danger">*</span></label><input type="tel" class="form-control" name="contact_number" id="adminPhoneNumber" pattern="[0-9+() .-]{7,20}" placeholder="+63 XXX XXX XXXX" required></div>
        <div class="row g-2 mb-3">
          <div class="col-md-6"><label class="form-label">Password <span class="text-danger">*</span></label><div class="input-field-wrapper position-relative"><i class="bi bi-lock field-icon-left"></i><input type="password" id="password" name="password" class="form-control custom-input px-5 js-password-strength" data-strength-for="password" placeholder="Enter a strong password" autocomplete="new-password" minlength="8" required><button type="button" class="btn-toggle-eye js-toggle-pwd" data-target="password" aria-label="Toggle password visibility"><i class="bi bi-eye"></i></button></div></div>
          <div class="col-md-6"><label class="form-label">Confirm Password <span class="text-danger">*</span></label><div class="input-field-wrapper position-relative"><i class="bi bi-lock field-icon-left"></i><input type="password" id="confirm_password" name="confirm_password" class="form-control custom-input px-5" placeholder="Re-enter your password" autocomplete="new-password" minlength="8" required><button type="button" class="btn-toggle-eye js-toggle-pwd" data-target="confirm_password" aria-label="Toggle password visibility"><i class="bi bi-eye"></i></button></div></div>
        </div>
        <div class="strength-meter-container mb-2" data-strength-for="password">
          <div class="password-strength-meter d-flex gap-1">
            <div class="strength-bar strength-segment flex-fill rounded-pill"></div>
            <div class="strength-bar strength-segment flex-fill rounded-pill"></div>
            <div class="strength-bar strength-segment flex-fill rounded-pill"></div>
            <div class="strength-bar strength-segment flex-fill rounded-pill"></div>
            <div class="strength-bar strength-segment flex-fill rounded-pill"></div>
          </div>
          <div class="d-flex justify-content-between mt-1">
            <span id="strength-label" class="strength-label small fw-bold text-danger" aria-live="polite"></span>
            <span id="requirements-counter" class="strength-count small text-muted" aria-live="polite">0/5 requirements met</span>
          </div>
        </div>
        <div class="mb-3" id="tenantTypeGroup"><label class="form-label">Tenant Type</label><select name="tenant_type" id="tenantTypeSelect" class="form-select"><option value="Student" selected>Student</option><option value="Employee">Employee</option></select></div>
        <div class="mb-3"><label class="form-label">Emergency Contact Name</label><input class="form-control" name="emergency_contact_name" placeholder="Full name"></div>
        <div class="mb-3"><label class="form-label">Emergency Contact Phone</label><input type="tel" class="form-control" name="emergency_contact_phone" placeholder="912 123 1234"></div>
        <button class="btn btn-sm btn-action-primary w-100 rounded-pill fw-bold">Save User</button>
      </section>
      <?php if (is_super_admin()): ?>
      <section class="registration-card role-selection-card">
        <div class="registration-card-heading"><span class="registration-icon"><i class="bi bi-shield-check"></i></span><h5>Identify Role</h5></div>
        <p class="text-muted small mb-4">Select the appropriate role for this user account.</p>
        <div class="role-selection-group">
          <input type="radio" name="role" id="admin_role_admin" value="admin" class="visually-hidden" required><label for="admin_role_admin" class="role-option-card"><span class="custom-radio-dot"></span><span class="role-icon-box"><i class="bi bi-shield-lock"></i></span><span><strong>Administrator</strong><small>Full access to system management features.</small></span></label>
          <input type="radio" name="role" id="admin_role_tenant" value="tenant" class="visually-hidden" checked><label for="admin_role_tenant" class="role-option-card"><span class="custom-radio-dot"></span><span class="role-icon-box"><i class="bi bi-person"></i></span><span><strong>Tenant</strong><small>Portal access for rooms, payments, and requests.</small></span></label>
        </div>
      </section>
      <?php else: ?>
      <input type="hidden" name="role" value="tenant">
      <p class="text-muted small">Only Super Admins can register administrator accounts. This form will create a tenant account.</p>
      <?php endif; ?>
    </div>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Tenant Type only applies to tenants; hide (and don't submit) it for administrators.
  const roleRadios = document.querySelectorAll('input[type="radio"][name="role"]');
  const typeGroup = document.getElementById('tenantTypeGroup');
  const typeSelect = document.getElementById('tenantTypeSelect');
  function syncTenantType() {
    const checked = document.querySelector('input[type="radio"][name="role"]:checked');
    const isTenant = !checked || checked.value === 'tenant';
    if (typeGroup) typeGroup.classList.toggle('d-none', !isTenant);
    if (typeSelect) typeSelect.disabled = !isTenant;
  }
  roleRadios.forEach(r => r.addEventListener('change', syncTenantType));
  syncTenantType();

  const passwordInput = document.getElementById('password') || document.querySelector('input[name="password"]');
  const bars = document.querySelectorAll('.strength-bar');
  const strengthText = document.getElementById('strength-label');
  const counterText = document.getElementById('requirements-counter');

  if (passwordInput) {
    const updateStrength = (value) => {
      const hasLength = value.length >= 8;
      const hasUpper = /[A-Z]/.test(value);
      const hasLower = /[a-z]/.test(value);
      const hasNumber = /[0-9]/.test(value);
      const hasSpecial = /[^A-Za-z0-9]/.test(value);

      const score = [hasLength, hasUpper, hasLower, hasNumber, hasSpecial].filter(Boolean).length;

      const levels = [
        { label: 'Very Weak', color: '#ef4444' },
        { label: 'Weak', color: '#f97316' },
        { label: 'Fair', color: '#eab308' },
        { label: 'Good', color: '#3b82f6' },
        { label: 'Very Strong', color: '#10b981' }
      ];

      if (strengthText) {
        strengthText.textContent = value.length === 0 ? '' : levels[score - 1]?.label || 'Very Weak';
        strengthText.style.color = value.length === 0 ? '' : levels[score - 1]?.color || '#ef4444';
      }

      if (counterText) {
        counterText.textContent = value.length === 0 ? '0/5 requirements met' : `${score}/5 requirements met`;
      }

      bars.forEach((bar, index) => {
        if (value.length === 0) {
          bar.style.backgroundColor = '#e2e8f0';
          bar.style.borderColor = 'rgba(148, 163, 184, 0.15)';
          return;
        }

        if (index < score) {
          bar.style.backgroundColor = levels[score - 1]?.color || '#10b981';
          bar.style.borderColor = levels[score - 1]?.color || '#10b981';
        } else {
          bar.style.backgroundColor = '#e2e8f0';
          bar.style.borderColor = 'rgba(148, 163, 184, 0.15)';
        }
      });
    };

    passwordInput.addEventListener('input', (e) => {
      updateStrength(e.target.value);
    });

    updateStrength(passwordInput.value);
  }
});
</script>

<?php
include __DIR__ . '/../includes/footer.php';
?>
