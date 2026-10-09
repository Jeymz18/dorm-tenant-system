<?php
require_once __DIR__ . '/config/app.php';
if (is_logged_in()) {
  require_login();
}

/** Make sure system_bugs can store the screenshot (safe to run repeatedly). */
function ensure_issue_image_column(PDO $db): void
{
    $has = $db->query("SHOW COLUMNS FROM system_bugs LIKE 'image_path'")->fetch();
    if (!$has) {
        $db->exec("ALTER TABLE system_bugs ADD COLUMN image_path VARCHAR(255) DEFAULT NULL AFTER page_url");
    }
    $hasIp = $db->query("SHOW COLUMNS FROM system_bugs LIKE 'ip_address'")->fetch();
    if (!$hasIp) {
        $db->exec("ALTER TABLE system_bugs ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL");
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $description = trim((string) ($_POST['description'] ?? ''));
    $pageUrl = trim((string) ($_POST['page_url'] ?? ''));
    if (mb_strlen($pageUrl) > 500) {
        $pageUrl = mb_substr($pageUrl, 0, 500);
    }
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $limitDb = get_db();
    ensure_issue_image_column($limitDb);
    $recent = $limitDb->prepare('SELECT COUNT(*) FROM system_bugs WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
    $recent->execute([$ip]);
    if ((int) $recent->fetchColumn() >= 5) {
        $error = 'You have sent several reports already. Please try again in an hour.';
    } elseif ($description === '' || mb_strlen($description) > 10000) {
        $error = 'Please describe the issue (up to 10,000 characters).';
    } else {
        try {
            $imagePath = handle_upload('issue_image', 'issues', ['jpg', 'jpeg', 'png'], 5 * 1024 * 1024);
        } catch (RuntimeException $e) {
            $imagePath = null;
            $error = $e->getMessage();
        }
        if ($error === '') {
            $db = get_db();
            ensure_issue_image_column($db);
            $userId = is_logged_in() ? current_user_id() : null;
            $role = is_logged_in() ? current_role() : 'guest';
            $stmt = $db->prepare('INSERT INTO system_bugs (user_id, role, description, page_url, image_path, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $role, $description, $pageUrl, $imagePath, $ip]);

            // Email the report so it reaches the inbox set in includes/email.php.
            $reporter = 'Guest (not logged in)';
            if ($userId !== null) {
                $u = $db->prepare('SELECT first_name, last_name, email FROM users WHERE user_id = ?');
                $u->execute([$userId]);
                if ($row = $u->fetch(PDO::FETCH_ASSOC)) {
                    $reporter = trim($row['first_name'] . ' ' . $row['last_name']) . ' <' . $row['email'] . '> (' . $role . ')';
                }
            }
            $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $body = "<div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto'>"
                . "<div style='background:#800000;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0'><strong>New issue report</strong></div>"
                . "<div style='border:1px solid #e9ecef;border-top:none;padding:20px;border-radius:0 0 8px 8px;color:#212529;line-height:1.6'>"
                . "<p><strong>Reported by:</strong> " . $h($reporter) . "</p>"
                . "<p><strong>Page:</strong> " . ($pageUrl !== '' ? $h($pageUrl) : '—') . "</p>"
                . "<p><strong>Date:</strong> " . date('M j, Y g:i A') . "</p>"
                . "<p><strong>Issue:</strong><br>" . nl2br($h($description)) . "</p>"
                . ($imagePath ? "<p><em>Picture attached.</em></p>" : "")
                . "</div></div>";
            send_email_alert(
                ISSUE_REPORT_EMAIL,
                'Admin',
                'New issue report' . ($userId !== null ? ' from ' . trim($_SESSION['first_name'] ?? '') : ' (guest)'),
                $body,
                $imagePath ? __DIR__ . '/' . $imagePath : null
            );
            redirect('/report_bug.php?submitted=1');
        }
    }
}

$refererPath = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH);
$initialPageUrl = is_string($refererPath) && $refererPath !== '' ? $refererPath : '';
if (!empty($_POST['page_url'])) { $initialPageUrl = (string) $_POST['page_url']; }
$pageTitle = 'Report an Issue';
include __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/page_header.php';
$portalLabel = !is_logged_in() ? 'Sign In' : (is_admin_role() ? 'Admin Portal' : 'Tenant Portal');
$portalPath = !is_logged_in() ? '/auth/login.php' : (is_admin_role() ? '/admin/dashboard.php' : '/tenant/dashboard.php');

ob_start();
?>
<?php if (isset($_GET['submitted'])): ?><div class="callout callout-success mb-3"><i class="bi bi-check-circle-fill"></i><div>Your report was submitted. Thank you for helping us improve the system.</div></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= clean($error) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="issue-form">
  <?= csrf_field() ?>
  <input type="hidden" name="page_url" value="<?= clean($initialPageUrl) ?>">
  <div class="mb-3">
    <label class="form-label fw-semibold">Picture of the issue</label>
    <label class="issue-dropzone" for="issue-image" id="issue-dropzone">
      <img id="issue-preview" alt="Preview of the attached picture" class="d-none">
      <span class="issue-dropzone-empty" id="issue-empty">
        <i class="bi bi-cloud-arrow-up"></i>
        <strong>Click to attach a picture</strong>
        <small>JPG or PNG, up to 5 MB</small>
      </span>
      <span class="issue-file-name d-none" id="issue-file-name"></span>
    </label>
    <input class="visually-hidden" type="file" id="issue-image" name="issue_image" accept="image/png,image/jpeg">
  </div>
  <div class="mb-4">
    <label class="form-label fw-semibold" for="issue-description">Describe the issue <span class="text-danger">*</span></label>
    <textarea class="form-control" id="issue-description" name="description" rows="5" maxlength="10000" required placeholder="Describe what happened."></textarea>
    <div class="form-text">Do not include passwords or payment card details.</div>
  </div>
  <button class="btn btn-primary-cta w-100 py-2.5 rounded-pill fw-bold d-flex align-items-center justify-content-center gap-2" type="submit"><i class="bi bi-send"></i> Submit report</button>
</form>
<?php
$formHtml = ob_get_clean();
?>
<?php if (!is_logged_in()): ?>
<div class="auth-screen auth-page auth-recovery">
  <canvas id="auth-bg-canvas" aria-hidden="true"></canvas>
  <div class="auth-panel">
    <div class="auth-panel-left auth-sidebar register-style-sidebar">
      <div class="sidebar-circle-accent"></div>
      <div class="position-relative z-1">
        <div class="icon-wrapper icon-box-xl sidebar-icon-wrapper mb-3"><i class="bi bi-exclamation-triangle-fill fs-4 text-white"></i></div>
        <h2 class="fw-bold fs-4 text-white mb-1">Dorm Tenant<br>Management System</h2>
        <p class="sidebar-description text-white-50 xs-text mb-0">Help us fix problems faster.</p>
      </div>
    </div>
    <div class="auth-panel-right auth-content">
      <a href="<?= BASE_URL ?>/auth/login.php" class="recovery-back"><i class="bi bi-arrow-left"></i> Back to Login</a>
      <div class="recovery-header">
        <span class="icon-wrapper icon-circle recovery-icon"><i class="bi bi-exclamation-triangle"></i></span>
        <div>
          <h2>Report an Issue</h2>
          <p class="recovery-subtitle">Attach a picture and tell us what went wrong.</p>
        </div>
      </div>
      <?= $formHtml ?>
    </div>
  </div>
  <footer class="auth-global-footer text-center"><p class="xxs-text text-white-50 mb-0">&copy; 2026 Teen T-ITans &middot; Dorm Tenant Management System</p></footer>
</div>
<?php else: ?>
<?php render_page_header('bi-exclamation-triangle-fill', 'Report an Issue', 'Attach a picture and tell us what went wrong.', null, $portalLabel, $portalPath); ?>
<div class="panel issue-panel"><?= $formHtml ?></div>
<?php endif; ?>
<script>
(function () {
  var input = document.getElementById('issue-image');
  var img = document.getElementById('issue-preview');
  var empty = document.getElementById('issue-empty');
  var name = document.getElementById('issue-file-name');
  var zone = document.getElementById('issue-dropzone');
  input.addEventListener('change', function () {
    var f = input.files && input.files[0];
    if (f && f.type.indexOf('image/') === 0) {
      img.src = URL.createObjectURL(f);
      img.classList.remove('d-none');
      empty.classList.add('d-none');
      name.textContent = f.name + ' · click to change';
      name.classList.remove('d-none');
      zone.classList.add('has-file');
    } else {
      img.classList.add('d-none');
      name.classList.add('d-none');
      empty.classList.remove('d-none');
      zone.classList.remove('has-file');
    }
  });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
