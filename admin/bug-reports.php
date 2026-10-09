<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');
$db = get_db();
$statuses = ['Pending', 'In Progress', 'Resolved'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $bugId = (int) ($_POST['bug_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    if ($bugId < 1 || !in_array($status, $statuses, true)) {
        flash('error', 'Choose a valid report and status.');
    } else {
        $stmt = $db->prepare('UPDATE system_bugs SET status = ? WHERE id = ?');
        $stmt->execute([$status, $bugId]);
        flash('success', 'Bug report status updated.');
    }
    redirect('/admin/bug-reports.php');
}

$statusCounts = ['Pending' => 0, 'In Progress' => 0, 'Resolved' => 0];
$countStatement = $db->prepare('SELECT status, COUNT(*) AS total FROM system_bugs GROUP BY status');
$countStatement->execute();
foreach ($countStatement->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
}
$reportStatement = $db->prepare('SELECT b.*, u.first_name, u.last_name, u.email
                 FROM system_bugs b LEFT JOIN users u ON u.user_id = b.user_id
                 ORDER BY FIELD(b.status, "Pending", "In Progress", "Resolved"), b.created_at DESC');
$reportStatement->execute();
$reports = $reportStatement->fetchAll();
$statusClasses = ['Pending' => 'warning', 'In Progress' => 'info', 'Resolved' => 'success'];
$pageTitle = 'Issue Reports';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/page_header.php';
render_page_header('bi-exclamation-triangle-fill', 'Issue Reports', 'Review submissions and track fixes.', '<a class="btn btn-sm btn-action-outline" href="' . BASE_URL . '/report_bug.php"><i class="bi bi-plus-lg"></i> Report an Issue</a>');
?>
<div class="stat-grid stat-grid-3">
  <?php foreach ($statusCounts as $status => $count): ?>
    <div class="stat-card"><div class="stat-card-body"><div class="stat-label"><?= clean($status) ?></div><div class="stat-value"><?= $count ?></div></div><div class="stat-icon stat-icon-outline"><i class="bi <?= $status === 'Resolved' ? 'bi-check-circle' : ($status === 'Pending' ? 'bi-exclamation-circle' : 'bi-wrench') ?>"></i></div></div>
  <?php endforeach; ?>
</div>
<div class="panel mt-2">
  <?php if (!$reports): ?>
    <p class="text-muted text-center py-4 mb-0">No issue reports have been submitted.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table app-table align-middle">
      <thead><tr><th>Report</th><th>Reporter</th><th>Page</th><th>Status</th><th>Submitted</th><th>Update</th></tr></thead>
      <tbody>
      <?php foreach ($reports as $report): ?>
        <tr>
          <td style="min-width:280px;max-width:460px"><div class="text-dark" style="white-space:pre-wrap"><?= clean($report['description']) ?></div><?php if (!empty($report['image_path'])): ?><a href="<?= BASE_URL . '/' . clean($report['image_path']) ?>" target="_blank" rel="noopener"><img src="<?= BASE_URL . '/' . clean($report['image_path']) ?>" alt="Attached picture" class="img-thumbnail mt-2" style="max-height:120px"></a><?php endif; ?></td>
          <td><div class="fw-semibold"><?= clean(trim(($report['first_name'] ?? '') . ' ' . ($report['last_name'] ?? '')) ?: 'Guest') ?></div><div class="small text-muted"><?= clean($report['role']) ?><?= !empty($report['email']) ? ' · ' . clean($report['email']) : '' ?></div></td>
          <td class="small"><?php if ($report['page_url'] !== ''): ?><code><?= clean($report['page_url']) ?></code><?php else: ?>—<?php endif; ?></td>
          <td><span class="badge text-bg-<?= $statusClasses[$report['status']] ?? 'secondary' ?>"><?= clean($report['status']) ?></span></td>
          <td class="small text-muted"><?= clean(date('M j, Y g:i A', strtotime($report['created_at']))) ?></td>
          <td>
            <form method="post" class="d-flex gap-2 align-items-center">
              <?= csrf_field() ?>
              <input type="hidden" name="bug_id" value="<?= (int) $report['id'] ?>">
              <select class="form-select form-select-sm" name="status" aria-label="Report status">
                <?php foreach ($statuses as $status): ?><option value="<?= clean($status) ?>" <?= $report['status'] === $status ? 'selected' : '' ?>><?= clean($status) ?></option><?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-action-outline" type="submit" aria-label="Update report status"><i class="bi bi-check-lg"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>