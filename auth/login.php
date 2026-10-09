<?php
require_once __DIR__ . '/../config/app.php';

if (is_logged_in()) {
    redirect(role_home_path());
}

$errors = [];
$oldEmail = '';

if (isset($_GET['deactivated'])) {
    $errors[] = 'This account has been deactivated. Please contact the administrator.';
}

if (isset($_GET['pwreset'])) {
    $errors[] = 'Your password was changed. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $oldEmail = str_input($_POST, 'email');
    $password = str_input($_POST, 'password', '', false);

    $stmt = get_db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$oldEmail]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $validPassword = is_array($user)
      && isset($user['password_hash'])
      && is_string($user['password_hash'])
      && password_verify($password, $user['password_hash']);

  if (!$validPassword) {
    $errors[] = 'Invalid email or password.';
    } elseif (!$user['is_active']) {
        $errors[] = 'This account has been deactivated. Please contact the administrator.';
    } elseif (!in_array($user['role'], ['super_admin', 'admin', 'tenant'], true)) {
      $errors[] = 'This account does not have access to a supported portal.';
    } else {
        login_user($user);
        redirect(role_home_path($user['role']));  
    }
}

$pageTitle = 'Sign In';
include __DIR__ . '/../includes/header.php';
?>
<div class="auth-screen auth-page">
  <canvas id="auth-bg-canvas" aria-hidden="true"></canvas>
  <div class="auth-panel">
    <div class="auth-panel-left auth-sidebar register-style-sidebar">
      <div class="sidebar-circle-accent"></div>
      <div class="position-relative z-1">
        <div class="icon-wrapper icon-box-xl sidebar-icon-wrapper mb-3"><i class="bi bi-box-arrow-in-right fs-4 text-white"></i></div>
        <h2 class="fw-bold fs-4 text-white mb-1">Dorm Tenant<br>Management System</h2>
        <p class="sidebar-description text-white-50 xs-text mb-3">Tenant Portal Access</p>
        <div class="sidebar-compact-list">
          <div class="sidebar-info-card"><div class="icon-wrapper icon-circle-sm sidebar-info-icon"><i class="bi bi-person fs-6"></i></div><div><h6>Personal Information</h6><span>Full name &amp; contact details</span></div></div>
          <div class="sidebar-info-card"><div class="icon-wrapper icon-circle-sm sidebar-info-icon"><i class="bi bi-at fs-6"></i></div><div><h6>Account Credentials</h6><span>Username &amp; secure password</span></div></div>
          <div class="sidebar-info-card"><div class="icon-wrapper icon-circle-sm sidebar-info-icon"><i class="bi bi-person-gear fs-6"></i></div><div><h6>Role Selection</h6><span>Admin or Tenant access</span></div></div>
        </div>
        <div class="demo-login-box compact-demo-login">
          <div class="demo-login-title">Quick Login (Demo)</div>
          <button type="button" class="demo-login-btn" data-email="admin@dorm.edu" data-password="Admin@123"><strong>Admin</strong><span>admin@dorm.edu</span></button>
          <button type="button" class="demo-login-btn" data-email="angel@student.dorm.edu" data-password="Tenant@123"><strong>Tenant</strong><span>angel@student.dorm.edu</span></button>
        </div>
      </div>
    </div>
    <div class="auth-panel-right">
      <div class="auth-form-badge login-badge"><span class="dot-indicator"></span> Secure Login Portal</div>
      <h2>Welcome Back</h2>
      <p class="text-muted small mb-4">Sign in to access your dashboard</p>

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <?php foreach ($errors as $err): ?><div><?= clean($err) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" id="loginForm" class="needs-validation login-form" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Username or Email Address <span class="text-danger">*</span></label>
          <div class="input-icon">
            <span class="icon-prefix"><i class="bi bi-envelope-fill"></i></span>
            <input type="email" name="email" id="loginEmail" class="form-control" placeholder="Enter your email" value="<?= clean($oldEmail) ?>" required autofocus>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Password <span class="text-danger">*</span></label>
          <div class="input-icon">
            <span class="icon-prefix"><i class="bi bi-lock-fill"></i></span>
            <input type="password" name="password" id="loginPassword" class="form-control" placeholder="Enter your password" value="" autocomplete="current-password" required>
          </div>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div class="form-check"><input type="checkbox" class="form-check-input" id="rememberMe" name="remember"><label class="form-check-label small text-muted" for="rememberMe">Remember Me</label></div>
          <a href="<?= BASE_URL ?>/auth/forgot_password.php" class="small fw-semibold text-maroon text-decoration-none">Forgot Password?</a>
        </div>
        <button type="submit" class="btn btn-primary-cta w-100 py-2.5 rounded-pill fw-bold d-flex align-items-center justify-content-center gap-2 mb-4">Login <i class="bi bi-chevron-right"></i></button>
        <hr class="my-4 text-muted opacity-25">
        <div class="d-flex justify-content-between align-items-center text-muted small login-footer-links">
          <a href="<?= BASE_URL ?>/auth/register.php" class="fw-bold text-maroon text-decoration-none d-flex align-items-center gap-1"><i class="bi bi-person-plus"></i> Register New Account</a>
          <a href="<?= BASE_URL ?>/report_bug.php" class="fw-bold text-maroon text-decoration-none d-flex align-items-center gap-1"><i class="bi bi-exclamation-triangle"></i> Report an Issue</a>
        </div>
      </form>
    </div>
  </div>
  <footer class="auth-global-footer text-center"><p class="xxs-text text-white-50 mb-0">&copy; 2026 Teen T-ITans &middot; Dorm Tenant Management System</p></footer>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
