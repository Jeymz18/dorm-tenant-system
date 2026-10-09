<?php
require_once __DIR__ . '/../config/app.php';

if (is_logged_in()) {
    redirect(role_home_path());
}

$sent = false;
$oldEmail = '';
$step = (string) ($_GET['step'] ?? '1');

if ($step === '2' && !empty($_SESSION['reset_email'])) {
  redirect('/auth/reset_password.php?step=2&email=' . urlencode((string) $_SESSION['reset_email']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = str_input($_POST, 'email');
    $oldEmail = $email;

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = get_db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = TRUE');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $_SESSION['reset_email'] = $email;
            $_SESSION['email'] = $email;
            $createdAt = $user['reset_otp_created_at'] ?? null;
            $secondsSinceSent = $createdAt ? time() - strtotime((string) $createdAt) : PHP_INT_MAX;
            if (empty($user['reset_otp_code']) || $secondsSinceSent >= 60 || empty($user['reset_otp_expires_at']) || strtotime((string) $user['reset_otp_expires_at']) <= time()) {
                $otp = (string) random_int(100000, 999999);
                $_SESSION['reset_email'] = $email;
                $_SESSION['otp_last_sent_at'] = time();
                $expires = date('Y-m-d H:i:s', time() + 10 * 60);
                get_db()->prepare('UPDATE users SET reset_otp_code = ?, reset_otp_attempts = 0, reset_otp_created_at = NOW(), reset_otp_expires_at = ? WHERE user_id = ?')
                        ->execute([password_hash($otp, PASSWORD_DEFAULT), $expires, $user['user_id']]);

                $body = "Hi {$user['first_name']}, use this code to reset your password:\n\n{$otp}\n\nThis code expires in 10 minutes. If you didn't request this, you can safely ignore this email.";
                if (!send_email_alert($user['email'], $user['first_name'], 'Your password reset code', email_template('Reset your password', $body))) {
                  get_db()->prepare('UPDATE users SET reset_otp_code = NULL, reset_otp_attempts = 0, reset_otp_created_at = NULL, reset_otp_expires_at = NULL WHERE user_id = ?')->execute([$user['user_id']]);
                  flash('error', 'We could not send the verification email. Please try again later.');
                  $sent = false;
                  goto render_forgot_password;
                }
            }
        }
        // Same message whether or not the email exists — otherwise this page
        // becomes a way to check which emails are registered.
        $sent = true;
    } else {
        flash('error', 'Please enter a valid email address.');
    }
}

render_forgot_password:
$pageTitle = 'Forgot Password';
include __DIR__ . '/../includes/header.php';
?>
<div class="auth-screen auth-page auth-recovery">
  <canvas id="auth-bg-canvas" aria-hidden="true"></canvas>
  <div class="auth-panel">
    <div class="auth-panel-left auth-sidebar register-style-sidebar">
      <div class="sidebar-circle-accent"></div>
      <div class="position-relative z-1">
        <div class="icon-wrapper icon-box-xl sidebar-icon-wrapper mb-3"><i class="bi bi-key-fill fs-4 text-white"></i></div>
        <h2 class="fw-bold fs-4 text-white mb-1">Dorm Tenant<br>Management System</h2>
      </div>
    </div>
    <div class="auth-panel-right auth-content">
      <a href="<?= BASE_URL ?>/auth/login.php" class="recovery-back"><i class="bi bi-arrow-left"></i> Back to Login</a>

      <div class="recovery-header">
        <span class="icon-wrapper icon-circle recovery-icon"><i class="bi bi-arrow-repeat"></i></span>
        <div>
          <h2>Password Recovery</h2>
          <p class="recovery-subtitle">Enter your registered email to receive a 6-digit verification code.</p>
          <div class="step-caption">
            <span class="step-track"><span class="bar active"></span><span class="bar"></span></span>
            Step 1 of 2
          </div>
        </div>
      </div>

      <?php if ($sent): ?>
        <div class="callout callout-success">
          <i class="bi bi-check-circle-fill"></i>
          <div>Verification code sent. It expires in 10 minutes.</div>
        </div>
        <a href="<?= BASE_URL ?>/auth/reset_password.php?step=2&amp;email=<?= urlencode($oldEmail) ?>" id="enterVerificationCodeBtn" class="btn btn-primary-cta w-100 py-2.5 rounded-pill fw-bold">Enter Verification Code</a>
      <?php else: ?>
        <div class="callout callout-info">
          <i class="bi bi-info-circle-fill"></i>
          <div>Enter your <strong>registered email address</strong> and we will send a 6-digit verification code.</div>
        </div>
        <?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= clean($msg) ?></div><?php endif; ?>
        <form method="post" class="needs-validation" novalidate>
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Registered Email Address <span class="text-danger">*</span></label>
            <div class="input-icon">
              <span class="icon-prefix"><i class="bi bi-envelope-fill"></i></span>
              <input type="email" name="email" class="form-control" placeholder="Enter your email" value="<?= clean($oldEmail) ?>" required autofocus>
            </div>
          </div>
          <button type="submit" class="btn btn-primary-cta w-100 py-2.5 rounded-pill fw-bold">Send Verification Code</button>
        </form>

        <div style="display: flex; justify-content: center; align-items: center; width: 100%; margin: 20px auto 0;">
          <div style="display: inline-flex; align-items: center; gap: 12px; padding: 6px 6px 6px 20px; background: #fdf2f2; border: 1px solid #f3d0d0; border-radius: 9999px;">
            <span style="color: #4b5563; font-size: 14px; font-weight: 500;">Remembered your password?</span>
            <a href="<?= BASE_URL ?>/auth/login.php"
               style="display: inline-flex; align-items: center; justify-content: center; background-color: #ffffff; color: #800000; font-weight: 700; font-size: 14px; border-radius: 9999px; padding: 6px 18px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); text-decoration: none; cursor: pointer; transition: all 0.2s ease;"
               onmouseover="this.style.backgroundColor='#f9fafb'; this.style.textDecoration='none';"
               onmouseout="this.style.backgroundColor='#ffffff'; this.style.textDecoration='none';">
              Sign in
            </a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <footer class="auth-global-footer text-center"><p class="xxs-text text-white-50 mb-0">&copy; 2026 Teen T-ITans &middot; Dorm Tenant Management System</p></footer>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
