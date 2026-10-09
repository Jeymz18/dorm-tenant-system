<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

if (!$tenant || $tenant['approval_status'] !== 'Approved') {
    redirect('/tenant/dashboard.php');
}

$room = null;
if ($tenant['room_id']) {
    $stmt = $db->prepare('SELECT room_number FROM dorm_rooms WHERE room_id = ?');
    $stmt->execute([$tenant['room_id']]);
    $room = $stmt->fetch();
}

mark_notifications_seen($db, current_user_id(), (int) $tenant['tenant_id'], $room['room_number'] ?? '__none__');

$stmt = $db->prepare("
        SELECT n.*, nr.seen_at, COALESCE(nr.is_resolved, 0) AS is_resolved,
          COALESCE(nr.is_dismissed, 0) AS is_dismissed
    FROM notifications n
    LEFT JOIN notification_reads nr ON nr.notification_id = n.notification_id AND nr.user_id = ?
    WHERE (n.target_type = 'all'
       OR (n.target_type = 'tenant' AND n.target_value = ?)
       OR (n.target_type = 'room' AND FIND_IN_SET(?, REPLACE(n.target_value, ' ', ''))))
      AND n.date_sent >= (SELECT date_created FROM users WHERE user_id = ?)
    ORDER BY n.date_sent DESC
");
$stmt->execute([current_user_id(), $tenant['tenant_id'], $room['room_number'] ?? '__none__', current_user_id()]);
$notifications = $stmt->fetchAll();

$typeIcon = ['Announcement' => '<i class="bi bi-megaphone-fill"></i>', 'Payment Reminder' => '<i class="bi bi-credit-card-fill"></i>', 'Contract Expiry Alert' => '<i class="bi bi-calendar2-warning"></i>'];
$typeTint = ['Announcement' => '', 'Payment Reminder' => 'tint-amber', 'Contract Expiry Alert' => 'tint-blue'];

$pageTitle = 'Notifications';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/page_header.php';
?>
<?php render_page_header('bi-bell-fill', 'Notifications', 'Announcements, payment reminders, and contract expiry alerts.', null, 'Tenant Portal', '/tenant/dashboard.php'); ?>

<div class="panel">
  <?php if (!$notifications): ?>
    <p class="text-muted py-4 text-center">Nothing here yet — you're all caught up.</p>
  <?php endif; ?>
  <?php foreach ($notifications as $n): ?>
    <div class="notification-item <?= $typeTint[$n['type']] ?? '' ?> <?= $n['seen_at'] ? 'notification-seen' : 'notification-unseen' ?>">
      <div class="notification-icon"><?= $typeIcon[$n['type']] ?? '<i class="bi bi-bell-fill"></i>' ?></div>
      <div class="flex-grow-1">
        <strong><?= clean($n['subject']) ?></strong>
        <span class="badge badge-secondary ms-2"><?= clean($n['type']) ?></span>
        <p class="text-muted small mb-1 mt-1"><?= clean($n['message']) ?></p>
        <div class="text-muted small"><?= clean(date('F j, Y g:i A', strtotime($n['date_sent']))) ?></div>
        <?php if ($n['seen_at']): ?><div class="text-muted small mt-1">Seen: <?= clean(date('M j, Y g:i A', strtotime($n['seen_at']))) ?></div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
