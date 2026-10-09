<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $type    = $_POST['type'] ?? 'Announcement';
    $target  = $_POST['target_type'] ?? 'all';
    $value   = str_input($_POST, 'target_value');
    $subject = str_input($_POST, 'subject');
    $message = str_input($_POST, 'message');

    $validTypes = ['Announcement', 'Payment Reminder', 'Contract Expiry Alert'];
    if (!in_array($type, $validTypes, true) || $subject === '' || $message === '') {
        flash('error', 'Please fill in a subject and message.');
    } elseif ($target !== 'all' && $value === '') {
        flash('error', 'Please specify the room number(s) or select a tenant.');
    } else {
        $db->prepare('INSERT INTO notifications (sender_id, type, subject, message, target_type, target_value) VALUES (?,?,?,?,?,?)')
           ->execute([current_user_id(), $type, $subject, $message, $target, $target === 'all' ? null : $value]);

        // Email whoever matches the target
        $recipients = [];
        if ($target === 'all') {
            $recipients = $db->query("SELECT u.email, u.first_name, u.phone FROM users u JOIN tenants t ON t.user_id=u.user_id WHERE t.approval_status='Approved' AND t.status NOT IN ('Evicted','Checked Out')")->fetchAll();
        } elseif ($target === 'room') {
            $rooms = array_map('trim', explode(',', $value));
            $in = implode(',', array_fill(0, count($rooms), '?'));
            $stmt = $db->prepare("SELECT u.email, u.first_name, u.phone FROM users u JOIN tenants t ON t.user_id=u.user_id JOIN dorm_rooms r ON r.room_id=t.room_id WHERE t.approval_status='Approved' AND t.status NOT IN ('Evicted','Checked Out') AND r.room_number IN ($in)");
            $stmt->execute($rooms);
            $recipients = $stmt->fetchAll();
        } elseif ($target === 'tenant') {
            $stmt = $db->prepare("SELECT u.email, u.first_name, u.phone FROM users u JOIN tenants t ON t.user_id=u.user_id WHERE t.tenant_id = ? AND t.approval_status='Approved' AND t.status NOT IN ('Evicted','Checked Out')");
            $stmt->execute([(int) $value]);
            $recipients = $stmt->fetchAll();
        }
        foreach ($recipients as $r) {
            send_email_alert($r['email'], $r['first_name'], $subject, email_template($subject, $message));
          if ($type !== 'Announcement' && !empty($r['phone'])) {
            error_log('[SMS notification queued] To: ' . $r['phone'] . ' | Subject: ' . $subject . ' | Message: ' . $message);
          }
        }

        flash('success', 'Notification sent to ' . count($recipients) . ' tenant(s).');
    }

    redirect('/admin/notifications.php');
}

mark_notifications_seen($db, current_user_id());

$recentStatement = $db->prepare("SELECT n.*, nr.seen_at, COALESCE(nr.is_resolved, 0) AS is_resolved,
    COALESCE(nr.is_dismissed, 0) AS is_dismissed
  FROM notifications n
  LEFT JOIN notification_reads nr ON nr.notification_id = n.notification_id AND nr.user_id = ?
  WHERE (n.type = 'Announcement' AND COALESCE(nr.is_dismissed, 0) = 0)
     OR (n.type <> 'Announcement' AND COALESCE(nr.is_resolved, 0) = 0)
     OR n.notification_id IN (
    SELECT recent.notification_id
    FROM (SELECT notification_id FROM notifications ORDER BY date_sent DESC LIMIT 15) recent
  )
  ORDER BY n.date_sent DESC");
$recentStatement->execute([current_user_id()]);
$recent = $recentStatement->fetchAll();
$tenantsForSelect = $db->query("SELECT t.tenant_id, u.first_name, u.last_name, u.phone FROM tenants t JOIN users u ON u.user_id=t.user_id WHERE t.approval_status='Approved' AND t.status NOT IN ('Evicted','Checked Out') ORDER BY u.first_name")->fetchAll();

$typeIcon = ['Announcement' => '<i class="bi bi-bell-fill"></i>', 'Payment Reminder' => '<i class="bi bi-wallet2"></i>', 'Contract Expiry Alert' => '<i class="bi bi-calendar2-warning"></i>'];
$typeTint = ['Announcement' => '', 'Payment Reminder' => 'tint-amber', 'Contract Expiry Alert' => 'tint-blue'];
$typeOptions = [
    'Announcement'           => ['<i class="bi bi-bell-fill"></i>', 'Announcements', 'Send general updates and important notices to tenants'],
    'Payment Reminder'       => ['<i class="bi bi-wallet2"></i>', 'Payment Reminders', 'Remind tenants about upcoming or overdue payments'],
    'Contract Expiry Alert'  => ['<i class="bi bi-calendar2-warning"></i>', 'Expiry Alerts', 'Alert tenants about expiring contracts or documents'],
];

$pageTitle = 'Notification Management';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/module_tabs.php';
require_once __DIR__ . '/../includes/page_header.php';
?>
<?php render_page_header('bi-bell-fill', 'Notification Management', 'Send announcements, payment reminders, and expiry alerts to tenants.'); ?>
<?php render_module_tabs([
  ['key' => 'announcements', 'label' => 'Announcements', 'href' => '/admin/notifications.php#notification-announcements', 'target' => 'notification-announcements', 'selectValue' => 'Announcement'],
  ['key' => 'payments', 'label' => 'Payment Reminders', 'href' => '/admin/notifications.php#notification-payments', 'target' => 'notification-payments', 'selectValue' => 'Payment Reminder'],
  ['key' => 'expiry', 'label' => 'Expiry Alerts', 'href' => '/admin/notifications.php#notification-expiry', 'target' => 'notification-expiry', 'selectValue' => 'Contract Expiry Alert'],
], 'announcements'); ?>

<div class="row g-4" id="compose">
  <div class="col-lg-4">
    <div class="panel h-100">
      <div class="panel-header"><h2>Notification Type</h2></div>
      <div class="type-option-list">
        <?php foreach ($typeOptions as $value => [$icon, $label, $desc]): ?>
          <label id="notification-<?= $value === 'Announcement' ? 'announcements' : ($value === 'Payment Reminder' ? 'payments' : 'expiry') ?>" class="type-option anchor-target <?= $value === 'Announcement' ? 'selected' : '' ?>" data-value="<?= clean($value) ?>">
            <input type="radio" name="type_display" value="<?= clean($value) ?>" <?= $value === 'Announcement' ? 'checked' : '' ?>>
            <span class="type-icon"><?= $icon ?></span>
            <span><strong><?= clean($label) ?></strong><p><?= clean($desc) ?></p></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="panel">
      <div class="panel-header"><h2>Compose Message</h2></div>
      <form method="post" id="composeForm">
        <?= csrf_field() ?>
        <input type="hidden" name="type" id="typeInput" value="Announcement">
        <div class="mb-3">
          <label class="form-label">Recipients</label>
          <select class="form-select" name="target_type" id="targetType" required>
            <option value="all">All Tenants</option>
            <option value="room">Specific Room(s)</option>
            <option value="tenant">Specific Tenant</option>
          </select>
        </div>
        <div class="mb-3 d-none" id="targetRoomField">
          <label class="form-label">Room Numbers (comma-separated)</label>
          <input type="text" class="form-control" name="target_value" id="targetValueRoom" placeholder="e.g. 204, 301">
        </div>
        <div class="mb-3 d-none" id="targetTenantField">
          <label class="form-label">Tenant</label>
          <select class="form-select" name="target_value_tenant" id="targetValueTenant">
            <option value="">Choose…</option>
            <?php foreach ($tenantsForSelect as $t): ?><option value="<?= $t['tenant_id'] ?>"><?= clean($t['first_name'] . ' ' . $t['last_name']) ?><?= $t['phone'] ? ' (' . clean($t['phone']) . ')' : '' ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Subject</label><input type="text" class="form-control" id="subjectInput" name="subject" placeholder="Enter announcement subject" required></div>
        <div class="mb-3"><label class="form-label">Message</label><textarea class="form-control" id="messageInput" name="message" rows="4" placeholder="Write your message here…" required></textarea></div>
        <div class="mb-3">
          <label class="form-label">Preview</label>
          <div class="preview-box" id="previewBox">Your message will appear here…</div>
        </div>
        <button class="btn btn-action-primary w-100"><i class="bi bi-send-fill"></i> Send Notification</button>
      </form>
    </div>
  </div>
</div>

<div class="panel mt-2">
  <div class="panel-header"><h2>Recent Notifications</h2></div>
  <?php if (!$recent): ?><p class="text-muted py-3">No notifications sent yet.</p><?php endif; ?>
  <?php foreach ($recent as $n): ?>
    <div class="notification-item <?= $typeTint[$n['type']] ?? '' ?> <?= $n['seen_at'] ? 'notification-seen' : 'notification-unseen' ?>">
      <div class="notification-icon"><?= $typeIcon[$n['type']] ?? '<i class="bi bi-bell-fill"></i>' ?></div>
      <div class="flex-grow-1">
        <strong><?= clean($n['subject']) ?></strong>
        <p class="text-muted small mb-1"><?= clean($n['message']) ?></p>
        <div class="text-muted small">
          <?= clean($n['target_type'] === 'all' ? 'All Tenants' : ($n['target_type'] === 'room' ? 'Room(s): ' . $n['target_value'] : (trim(($n['target_first_name'] ?? '') . ' ' . ($n['target_last_name'] ?? '')) ?: 'Specific tenant'))) ?>
          · <?= clean(date('M j, Y', strtotime($n['date_sent']))) ?>
        </div>
        <?php if ($n['seen_at']): ?><div class="text-muted small mt-1">Seen: <?= clean(date('M j, Y g:i A', strtotime($n['seen_at']))) ?></div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php
$extraScripts = "<script>
(function () {
  const targetType = document.getElementById('targetType');
  const roomField = document.getElementById('targetRoomField');
  const tenantField = document.getElementById('targetTenantField');
  const roomInput = document.getElementById('targetValueRoom');
  const tenantSelect = document.getElementById('targetValueTenant');
  const subjectInput = document.getElementById('subjectInput');
  const messageInput = document.getElementById('messageInput');
  const previewBox = document.getElementById('previewBox');
  if (!targetType || !roomField || !tenantField || !roomInput || !tenantSelect || !subjectInput || !messageInput || !previewBox) return;

  function syncTargetName() {
    roomInput.name = tenantSelect.name = '';
    if (targetType.value === 'room') roomInput.name = 'target_value';
    if (targetType.value === 'tenant') tenantSelect.name = 'target_value';
  }
  targetType.addEventListener('change', function () {
    roomField.classList.toggle('d-none', this.value !== 'room');
    tenantField.classList.toggle('d-none', this.value !== 'tenant');
    syncTargetName();
  });
  syncTargetName();

  document.querySelectorAll('.type-option').forEach(function (opt) {
    opt.addEventListener('click', function () {
      document.querySelectorAll('.type-option').forEach(function (item) { item.classList.remove('selected'); });
      opt.classList.add('selected');
      const radio = opt.querySelector('input[type=radio]');
      const typeInput = document.getElementById('typeInput');
      if (radio) radio.checked = true;
      if (typeInput) typeInput.value = opt.dataset.value;
    });
  });

  function updatePreview() {
    const subject = subjectInput.value.trim();
    const message = messageInput.value.trim();
    previewBox.textContent = (subject || message) ? (subject ? subject + ' - ' : '') + message : 'Your message will appear here...';
  }
  subjectInput.addEventListener('input', updatePreview);
  messageInput.addEventListener('input', updatePreview);
})();
</script>";
include __DIR__ . '/../includes/footer.php';
?>
